<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function setting(string $key, mixed $default = null): mixed
    {
        $setting = PlatformSetting::query()
            ->where('key', $key)
            ->first();

        return $setting?->value ?? $default;
    }

    public function registrationEnabled(): bool
    {
        return (bool) $this->setting('registration_enabled', true);
    }

    public function trialEnabled(): bool
    {
        return (bool) $this->setting('trial_enabled', true);
    }

    public function trialDays(): int
    {
        return max(0, (int) $this->setting('trial_days', 30));
    }

    public function gracePeriodDays(): int
    {
        return max(0, (int) $this->setting('grace_period_days', 3));
    }

    public function defaultPlan(): ?Plan
    {
        $planCode = (string) $this->setting('default_plan_code', 'starter');

        return Plan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();
    }

    public function startTrial(Tenant $tenant): ?Subscription
    {
        return DB::transaction(function () use ($tenant): ?Subscription {
            $lockedTenant = Tenant::query()
                ->whereKey($tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $lockedTenant->getKey())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $plan = $this->defaultPlan();

            if ($plan === null) {
                return null;
            }

            $startsAt = now();
            $trialEnabled = $this->trialEnabled();
            $trialDays = $this->trialDays();
            $endsAt = $trialEnabled ? $startsAt->copy()->addDays($trialDays) : $startsAt->copy();
            $status = $trialEnabled && $trialDays > 0 ? 'trialing' : 'expired';

            $subscription = Subscription::withoutGlobalScopes()->create([
                'tenant_id' => $lockedTenant->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => $status,
                'trial_starts_at' => $startsAt,
                'trial_ends_at' => $endsAt,
            ]);

            $lockedTenant->forceFill([
                'subscription_ends_at' => $endsAt,
            ])->save();

            return $subscription;
        });
    }
}
