<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlatformSetting;

class SubscriptionService
{
    public function setting(string $key, mixed $default = null): mixed
    {
        $setting = PlatformSetting::query()
            ->where('key', $key)
            ->first();

        return $setting?->value ?? $default;
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
}
