<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Services\PaymentGateways\PaymentGatewayInterface;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionPaymentReconciliationService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
    ) {
    }

    /**
     * Reconcile a signed Midtrans notification against Midtrans' transaction
     * status API. The webhook payload itself is never used as payment proof.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): string
    {
        $providerReference = (string) ($payload['order_id'] ?? '');

        if ($providerReference === '') {
            return 'ignored';
        }

        $payment = SubscriptionPayment::withoutGlobalScopes()
            ->where('provider', 'midtrans')
            ->where('provider_reference', $providerReference)
            ->first();

        if ($payment === null) {
            Log::warning('Midtrans webhook references an unknown subscription payment.', [
                'provider_reference' => $providerReference,
            ]);

            return 'unknown_payment';
        }

        $status = $this->gateway->getPaymentStatus($providerReference);

        if ($status->providerReference !== $providerReference) {
            Log::warning('Midtrans status response reference does not match the requested payment.', [
                'expected_reference' => $providerReference,
                'returned_reference' => $status->providerReference,
                'payment_id' => $payment->getKey(),
            ]);

            return 'reference_mismatch';
        }

        if ($status->status === 'succeeded') {
            if (
                $status->currency !== 'IDR'
                || $status->grossAmount === null
                || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $status->grossAmount)
                || BigDecimal::of($status->grossAmount)->compareTo(BigDecimal::of($payment->amount)) !== 0
            ) {
                Log::critical('Midtrans reported a successful payment with an unexpected amount or currency.', [
                    'payment_id' => $payment->getKey(),
                    'provider_reference' => $providerReference,
                    'expected_amount' => $payment->amount,
                    'received_amount' => $status->grossAmount,
                    'received_currency' => $status->currency,
                ]);

                return 'amount_mismatch';
            }
        }

        return DB::transaction(function () use ($payment, $status): string {
            $lockedPayment = SubscriptionPayment::withoutGlobalScopes()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = SubscriptionInvoice::withoutGlobalScopes()
                ->whereKey($lockedPayment->subscription_invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($status->status === 'succeeded') {
                if ($lockedPayment->status === 'succeeded') {
                    return 'already_processed';
                }

                if (
                    $invoice->currency !== 'IDR'
                    || BigDecimal::of($invoice->total_amount)->compareTo(BigDecimal::of($lockedPayment->amount)) !== 0
                ) {
                    Log::critical('Subscription payment amount does not match its invoice snapshot.', [
                        'payment_id' => $lockedPayment->getKey(),
                        'invoice_id' => $invoice->getKey(),
                        'payment_amount' => $lockedPayment->amount,
                        'invoice_amount' => $invoice->total_amount,
                        'invoice_currency' => $invoice->currency,
                    ]);

                    return 'amount_mismatch';
                }

                $paidAt = $status->paidAt ?? now();

                $lockedPayment->forceFill([
                    'status' => 'succeeded',
                    'payment_method' => $status->paymentMethod ?? $lockedPayment->payment_method,
                    'paid_at' => $paidAt,
                    'failure_reason' => null,
                ])->save();

                // Another attempt may have settled this invoice first. Record
                // this provider result, but never extend the subscription twice.
                if ($invoice->status === 'paid') {
                    Log::critical('A second Midtrans payment attempt settled an invoice that was already paid.', [
                        'payment_id' => $lockedPayment->getKey(),
                        'invoice_id' => $invoice->getKey(),
                        'provider_reference' => $lockedPayment->provider_reference,
                    ]);

                    return 'invoice_already_paid';
                }

                $invoice->forceFill([
                    'status' => 'paid',
                    'paid_at' => $paidAt,
                ])->save();

                $subscription = Subscription::withoutGlobalScopes()
                    ->whereKey($invoice->subscription_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $invoicePeriodEnd = $invoice->period_end;
                $currentPeriodEnd = $subscription->current_period_end;

                // Never move an already-extended subscription backwards.
                if ($currentPeriodEnd === null || $invoicePeriodEnd->gt($currentPeriodEnd)) {
                    $subscription->forceFill([
                        'plan_id' => $invoice->plan_id ?? $subscription->plan_id,
                        'status' => 'active',
                        'current_period_start' => $invoice->period_start,
                        'current_period_end' => $invoicePeriodEnd,
                        'grace_ends_at' => null,
                    ])->save();

                    $tenant = Tenant::withoutGlobalScopes()
                        ->whereKey($invoice->tenant_id)
                        ->lockForUpdate()
                        ->first();

                    if ($tenant !== null) {
                        $tenantEnd = $tenant->subscription_ends_at;

                        if ($tenantEnd === null || $invoicePeriodEnd->gt($tenantEnd)) {
                            $tenant->forceFill([
                                'subscription_ends_at' => $invoicePeriodEnd,
                            ])->save();
                        }
                    }
                }

                return 'paid';
            }

            if ($lockedPayment->status === 'succeeded') {
                return 'already_processed';
            }

            if (in_array($status->status, ['pending', 'failed', 'expired'], true)) {
                $lockedPayment->forceFill([
                    'status' => $status->status,
                    'payment_method' => $status->paymentMethod ?? $lockedPayment->payment_method,
                    'failure_reason' => $status->failureReason,
                ])->save();

                return $status->status;
            }

            return 'ignored';
        });
    }
}
