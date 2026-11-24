<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionInvoiceService
{
    public function createInitialInvoice(Tenant $tenant, Plan $plan, string $billingInterval = 'monthly'): SubscriptionInvoice
    {
        return $this->createInvoice($tenant, $plan, 'initial', $billingInterval);
    }

    public function createRenewalInvoice(Tenant $tenant, string $billingInterval = 'monthly'): SubscriptionInvoice
    {
        return DB::transaction(function () use ($tenant, $billingInterval): SubscriptionInvoice {
            $this->assertOwnerCanManage($tenant);

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $plan = Plan::query()->findOrFail($subscription->plan_id);

            return $this->buildInvoice($tenant, $subscription, $plan, 'renewal', $billingInterval);
        });
    }

    public function createPaymentAttempt(
        SubscriptionInvoice $invoice,
        string $provider,
        ?string $paymentMethod = null,
        ?string $idempotencyKey = null,
    ): SubscriptionPayment {
        return DB::transaction(function () use ($invoice, $provider, $paymentMethod, $idempotencyKey): SubscriptionPayment {
            $lockedInvoice = SubscriptionInvoice::withoutGlobalScopes()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Authorize against the persisted invoice tenant, not caller-controlled model attributes.
            $this->assertOwnerCanManageTenantId((int) $lockedInvoice->tenant_id);

            if (! in_array($lockedInvoice->status, ['issued', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice ini tidak dapat menerima percobaan pembayaran baru.',
                ]);
            }

            if (blank($provider)) {
                throw ValidationException::withMessages([
                    'provider' => 'Provider pembayaran wajib diisi.',
                ]);
            }

            if (BigDecimal::of($lockedInvoice->total_amount)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice dengan total nol tidak memerlukan percobaan pembayaran.',
                ]);
            }

            if ($idempotencyKey !== null) {
                $existing = SubscriptionPayment::withoutGlobalScopes()
                    ->where('tenant_id', $lockedInvoice->tenant_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if ((int) $existing->subscription_invoice_id !== (int) $lockedInvoice->getKey()) {
                        throw ValidationException::withMessages([
                            'payment' => 'Idempotency key sudah digunakan untuk invoice lain.',
                        ]);
                    }

                    return $existing;
                }
            }

            $attempt = SubscriptionPayment::create([
                'tenant_id' => $lockedInvoice->tenant_id,
                'subscription_invoice_id' => $lockedInvoice->getKey(),
                'provider' => $provider,
                'payment_method' => $paymentMethod,
                'amount' => $lockedInvoice->total_amount,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($lockedInvoice->status === 'issued') {
                $lockedInvoice->forceFill(['status' => 'pending'])->save();
            }

            return $attempt;
        });
    }

    private function createInvoice(Tenant $tenant, Plan $plan, string $type, string $billingInterval): SubscriptionInvoice
    {
        return DB::transaction(function () use ($tenant, $plan, $type, $billingInterval): SubscriptionInvoice {
            $this->assertOwnerCanManage($tenant);

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPlan = Plan::query()->findOrFail($plan->getKey());

            if (! $lockedPlan->is_active) {
                throw ValidationException::withMessages([
                    'plan' => 'Paket ini tidak tersedia untuk pembelian baru.',
                ]);
            }

            return $this->buildInvoice($tenant, $subscription, $lockedPlan, $type, $billingInterval);
        });
    }

    private function buildInvoice(
        Tenant $tenant,
        Subscription $subscription,
        Plan $plan,
        string $type,
        string $billingInterval,
    ): SubscriptionInvoice {
        if (! in_array($billingInterval, ['monthly', 'yearly'], true)) {
            throw ValidationException::withMessages([
                'billing_interval' => 'Periode tagihan harus bulanan atau tahunan.',
            ]);
        }

        $price = $billingInterval === 'yearly'
            ? $plan->yearly_price
            : $plan->monthly_price;

        if ($price === null) {
            throw ValidationException::withMessages([
                'billing_interval' => 'Paket ini tidak memiliki harga untuk periode yang dipilih.',
            ]);
        }

        $periodStart = $this->nextPeriodStart($subscription);
        $periodEnd = $billingInterval === 'yearly'
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();

        $existing = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->where('type', $type)
            ->where('plan_id', $plan->getKey())
            ->where('billing_interval', $billingInterval)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->whereIn('status', ['issued', 'pending'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $amount = (string) $price;

        return SubscriptionInvoice::create([
            'tenant_id' => $tenant->getKey(),
            'subscription_id' => $subscription->getKey(),
            'plan_id' => $plan->getKey(),
            'invoice_number' => $this->invoiceNumber($tenant),
            'type' => $type,
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
            'billing_interval' => $billingInterval,
            'unit_price_snapshot' => $amount,
            'subtotal' => $amount,
            'discount_amount' => '0.00',
            'total_amount' => $amount,
            'currency' => $plan->currency,
            'status' => 'issued',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    private function nextPeriodStart(Subscription $subscription): Carbon
    {
        $candidate = $subscription->current_period_end ?? $subscription->trial_ends_at;

        return $candidate !== null && $candidate->isFuture()
            ? $candidate->copy()
            : now();
    }

    private function invoiceNumber(Tenant $tenant): string
    {
        return sprintf(
            'SUB-%d-%s-%s',
            $tenant->getKey(),
            now()->format('Ymd'),
            Str::upper(Str::random(8)),
        );
    }

    private function assertOwnerCanManage(Tenant $tenant): void
    {
        $this->assertOwnerCanManageTenantId((int) $tenant->getKey());
    }

    private function assertOwnerCanManageTenantId(int $tenantId): void
    {
        $user = Auth::user();

        if (
            ! $user instanceof User
            || ! $user->is_active
            || $user->role !== 'tenant_owner'
            || (int) $user->tenant_id !== $tenantId
        ) {
            throw new AuthorizationException('Hanya owner tenant yang dapat mengelola invoice subscription.');
        }
    }
}
