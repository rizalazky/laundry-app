<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        $invoice = $this->createInvoice($tenant, $plan, 'initial', $billingInterval);

        return $this->settleFreeInvoiceIfNeeded($invoice);
    }

    public function createRenewalInvoice(Tenant $tenant, string $billingInterval = 'monthly'): SubscriptionInvoice
    {
        $invoice = DB::transaction(function () use ($tenant, $billingInterval): SubscriptionInvoice {
            $this->assertOwnerCanManage($tenant);

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->current_period_end === null) {
                throw ValidationException::withMessages([
                    'subscription' => 'Renewal hanya tersedia setelah periode berlangganan pertama terbentuk.',
                ]);
            }
 

            $plan = Plan::query()->findOrFail($subscription->plan_id);

            return $this->buildInvoice($tenant, $subscription, $plan, 'renewal', $billingInterval);
        });

        return $this->settleFreeInvoiceIfNeeded($invoice);
    }

    public function createPlanPurchaseInvoice(Tenant $tenant, Plan $targetPlan, string $billingInterval = 'monthly'): SubscriptionInvoice
    {
        $invoice = DB::transaction(function () use ($tenant, $targetPlan, $billingInterval): SubscriptionInvoice {
            $this->assertOwnerCanManage($tenant);

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->current_period_end === null && $subscription->current_period_start === null) {
                $plan = Plan::query()->where('is_active', true)->findOrFail($targetPlan->getKey());

                return $this->buildInvoice($tenant, $subscription, $plan, 'initial', $billingInterval);
            }

            $plan = Plan::query()->where('is_active', true)->findOrFail($targetPlan->getKey());
            if (! in_array($billingInterval, ['monthly', 'yearly'], true)) {
                throw ValidationException::withMessages([
                    'billing_interval' => 'Periode tagihan harus bulanan atau tahunan.',
                ]);
            }

            $targetPrice = $billingInterval === 'yearly' ? $plan->yearly_price : $plan->monthly_price;
            if ($targetPrice === null || BigDecimal::of($targetPrice)->isLessThan(0)) {
                throw ValidationException::withMessages([
                    'plan' => 'Paket tidak memiliki harga valid untuk periode yang dipilih.',
                ]);
            }

            $periodEnd = $subscription->current_period_end;
            $hasActivePaidPeriod = $periodEnd !== null && $periodEnd->isFuture();

            if (! $hasActivePaidPeriod) {
                return $this->createPurchasedPlanInvoice(
                    $tenant, $subscription, $plan, 'renewal', $billingInterval,
                    now(), $this->periodEndFrom(now(), $billingInterval), (string) $targetPrice,
                );
            }

            $activeInvoice = SubscriptionInvoice::withoutGlobalScopes()
                ->where('subscription_id', $subscription->getKey())
                ->where('status', 'paid')
                ->where('plan_id', $subscription->plan_id)
                ->where('period_start', '<=', now())
                ->where('period_end', '>=', now())
                ->latest('id')
                ->first();

            $activeInterval = $activeInvoice?->billing_interval ?? $billingInterval;
            if ($activeInterval !== $billingInterval) {
                throw ValidationException::withMessages([
                    'billing_interval' => 'Perubahan paket harus menggunakan periode tagihan yang sama dengan paket aktif.',
                ]);
            }

            $currentPlan = Plan::query()->findOrFail($subscription->plan_id);
            $currentPrice = $billingInterval === 'yearly'
                ? $currentPlan->yearly_price
                : $currentPlan->monthly_price;

            if ($currentPrice === null) {
                throw ValidationException::withMessages([
                    'plan' => 'Harga paket aktif tidak tersedia untuk periode tagihan ini.',
                ]);
            }

            $comparison = BigDecimal::of($targetPrice)->compareTo(BigDecimal::of($currentPrice));
            if ($comparison > 0) {
                $periodStart = now();
                $currentPeriodStart = $subscription->current_period_start
                    ?? ($billingInterval === 'yearly' ? $periodEnd->copy()->subYear() : $periodEnd->copy()->subMonth());
                $totalSeconds = max(1, $currentPeriodStart->diffInSeconds($periodEnd));
                $remainingSeconds = max(0, now()->diffInSeconds($periodEnd, false));
                $difference = BigDecimal::of($targetPrice)->minus(BigDecimal::of($currentPrice));
                $amount = $difference
                    ->multipliedBy((string) $remainingSeconds)
                    ->dividedBy((string) $totalSeconds, 2, RoundingMode::HALF_UP)
                    ->toScale(2);

                return $this->createPurchasedPlanInvoice(
                    $tenant, $subscription, $plan, 'upgrade', $billingInterval,
                    $periodStart, $periodEnd, (string) $amount,
                );
            }

            return $this->createPurchasedPlanInvoice(
                $tenant, $subscription, $plan, 'renewal', $billingInterval,
                $periodEnd, $this->periodEndFrom($periodEnd, $billingInterval), (string) $targetPrice,
            );
        });

        return $this->settleFreeInvoiceIfNeeded($invoice);
    }

    private function createPurchasedPlanInvoice(
        Tenant $tenant,
        Subscription $subscription,
        Plan $plan,
        string $type,
        string $billingInterval,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $amount,
    ): SubscriptionInvoice {
        $existing = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->where('plan_id', $plan->getKey())
            ->where('type', $type)
            ->where('period_start', $periodStart)
            ->whereIn('status', ['issued', 'pending'])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $openInvoice = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->whereIn('status', ['issued', 'pending'])
            ->exists();

        $paidFuturePurchase = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', 'paid')
            ->where('period_start', '>', now())
            ->where('period_end', '>', now())
            ->exists();

        if ($openInvoice || $paidFuturePurchase) {
            throw ValidationException::withMessages([
                'invoice' => 'Masih ada pembelian paket yang belum selesai atau menunggu masa aktif. Selesaikan periode tersebut sebelum membeli paket lain.',
            ]);
        }

        $fullPrice = $billingInterval === 'yearly' ? $plan->yearly_price : $plan->monthly_price;
        // Proration is the invoice subtotal itself, not a promotional discount.
        $discount = '0.00';

        return SubscriptionInvoice::create([
            'tenant_id' => $tenant->getKey(),
            'subscription_id' => $subscription->getKey(),
            'plan_id' => $plan->getKey(),
            'invoice_number' => $this->invoiceNumber($tenant),
            'type' => $type,
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
            'billing_interval' => $billingInterval,
            'unit_price_snapshot' => (string) $fullPrice,
            'subtotal' => $amount,
            'discount_amount' => (string) $discount,
            'total_amount' => $amount,
            'currency' => $plan->currency,
            'status' => 'issued',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    private function periodEndFrom(Carbon $periodStart, string $billingInterval): Carbon
    {
        return $billingInterval === 'yearly'
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();
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

            if (blank($provider)) {
                throw ValidationException::withMessages([
                    'provider' => 'Provider pembayaran wajib diisi.',
                ]);
            }

            if ($idempotencyKey !== null) {
                $existing = SubscriptionPayment::withoutGlobalScopes()
                    ->where('tenant_id', $lockedInvoice->tenant_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if (
                        (int) $existing->subscription_invoice_id !== (int) $lockedInvoice->getKey()
                        || $existing->provider !== $provider
                    ) {
                        throw ValidationException::withMessages([
                            'payment' => 'Idempotency key sudah digunakan untuk invoice atau provider lain.',
                        ]);
                    }

                    return $existing;
                }
            }

            if (! in_array($lockedInvoice->status, ['issued', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice ini tidak dapat menerima percobaan pembayaran baru.',
                ]);
            }

            if (BigDecimal::of($lockedInvoice->total_amount)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice dengan total nol tidak memerlukan percobaan pembayaran.',
                ]);
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

            if ($type === 'initial' && ($subscription->current_period_start !== null || $subscription->current_period_end !== null)) {
                throw ValidationException::withMessages([
                    'subscription' => 'Invoice pembelian awal hanya tersedia sebelum periode berbayar pertama dimulai.',
                ]);
            }

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

        if (BigDecimal::of($price)->isLessThan(0)) {
            throw ValidationException::withMessages([
                'plan' => 'Harga paket tidak boleh bernilai negatif.',
            ]);
        }

        $existing = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->where('type', $type)
            ->where('plan_id', $plan->getKey())
            ->where('billing_interval', $billingInterval)
            ->whereIn('status', ['issued', 'pending'])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $conflictingInvoice = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->whereIn('status', ['issued', 'pending'])
            ->latest('id')
            ->first();

        $paidFuturePurchase = SubscriptionInvoice::withoutGlobalScopes()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', 'paid')
            ->where('period_start', '>', now())
            ->where('period_end', '>', now())
            ->exists();

        if ($paidFuturePurchase) {
            throw ValidationException::withMessages([
                'invoice' => 'Sudah ada pembelian paket yang menunggu masa aktif. Invoice perpanjangan baru dapat dibuat setelah paket tersebut aktif.',
            ]);
        }

        if ($conflictingInvoice !== null) {
            throw ValidationException::withMessages([
                'invoice' => 'Masih ada invoice aktif untuk periode ini. Selesaikan pembayaran invoice tersebut sebelum membuat invoice paket atau periode lain.',
            ]);
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

    private function settleFreeInvoiceIfNeeded(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if (BigDecimal::of($invoice->total_amount)->isGreaterThan(0) || $invoice->status === 'paid') {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice): SubscriptionInvoice {
            $lockedInvoice = SubscriptionInvoice::withoutGlobalScopes()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status === 'paid') {
                return $lockedInvoice;
            }

            if (! in_array($lockedInvoice->status, ['issued', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice gratis ini tidak lagi dapat diaktifkan.',
                ]);
            }

            if (BigDecimal::of($lockedInvoice->total_amount)->isGreaterThan(0)) {
                return $lockedInvoice;
            }

            $paidAt = now();

            $lockedInvoice->forceFill([
                'status' => 'paid',
                'paid_at' => $paidAt,
            ])->save();

            app(SubscriptionInvoiceActivationService::class)->activateIfDue($lockedInvoice);

            return $lockedInvoice->refresh();
        });
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
