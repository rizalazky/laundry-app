<?php

namespace App\Services;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\PaymentGateways\PaymentGatewayInterface;
use App\Services\PaymentGateways\PaymentGatewayStatusResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionPaymentService
{
    public function __construct(
        private readonly SubscriptionInvoiceService $invoiceService,
        private readonly PaymentGatewayInterface $gateway,
    ) {
    }

    public function startPayment(
        SubscriptionInvoice $invoice,
        ?string $paymentMethod = null,
        ?string $idempotencyKey = null,
    ): SubscriptionPayment {
        $provider = (string) config('services.payment_gateway.driver');

        if ($provider === '') {
            throw new RuntimeException('Payment gateway provider belum dikonfigurasi.');
        }

        $payment = $this->invoiceService->createPaymentAttempt(
            invoice: $invoice,
            provider: $provider,
            paymentMethod: $paymentMethod,
            idempotencyKey: $idempotencyKey,
        );

        if (filled($payment->payment_url)) {
            return $payment;
        }

        $result = $this->gateway->createPayment($payment);

        return DB::transaction(function () use ($payment, $result): SubscriptionPayment {
            $lockedPayment = SubscriptionPayment::withoutGlobalScopes()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (filled($lockedPayment->payment_url)) {
                return $lockedPayment;
            }

            $lockedPayment->forceFill([
                'provider_reference' => $result->providerReference,
                'payment_url' => $result->paymentUrl,
                'expires_at' => $result->expiresAt,
            ])->save();

            return $lockedPayment->refresh();
        });
    }

    public function getPaymentStatus(SubscriptionPayment $payment): PaymentGatewayStatusResult
    {
        $this->assertSupportedProvider($payment);

        if (blank($payment->provider_reference)) {
            throw new RuntimeException('Payment attempt belum memiliki referensi provider.');
        }

        return $this->gateway->getPaymentStatus($payment->provider_reference);
    }

    public function cancelPayment(SubscriptionPayment $payment): void
    {
        $this->assertSupportedProvider($payment);

        if (blank($payment->provider_reference)) {
            throw new RuntimeException('Payment attempt belum memiliki referensi provider.');
        }

        $this->gateway->cancelPayment($payment->provider_reference);
    }

    private function assertSupportedProvider(SubscriptionPayment $payment): void
    {
        if ($payment->provider !== config('services.payment_gateway.driver')) {
            throw new RuntimeException('Payment attempt dibuat oleh provider yang berbeda dari provider aktif.');
        }
    }
}
