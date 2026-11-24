<?php

namespace App\Services;

use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class EntitlementService
{
    public function accessState(Tenant $tenant): string
    {
        if (! $tenant->is_active) {
            return 'suspended';
        }

        $subscription = $this->subscription($tenant);

        if ($subscription === null) {
            return $tenant->subscription_ends_at === null || $tenant->subscription_ends_at->isFuture()
                ? 'active'
                : 'read_only';
        }

        $now = now();

        return match ($subscription->status) {
            'trialing' => $subscription->trial_ends_at !== null && $now->lt($subscription->trial_ends_at)
                ? 'trialing'
                : 'read_only',
            'active' => $this->periodIsCurrent($subscription, $now)
                ? 'active'
                : ($this->isWithinGracePeriod($subscription, $now) ? 'grace' : 'read_only'),
            'past_due' => $this->isWithinGracePeriod($subscription, $now) ? 'grace' : 'read_only',
            default => 'read_only',
        };
    }

    public function canWrite(Tenant $tenant): bool
    {
        return in_array($this->accessState($tenant), ['trialing', 'active', 'grace'], true);
    }

    public function assertCanWrite(Tenant $tenant): void
    {
        if (! $this->canWrite($tenant)) {
            throw new AuthorizationException('Masa aktif langganan telah berakhir. Aplikasi berada dalam mode read-only.');
        }
    }

    public function hasFeature(Tenant $tenant, string $featureCode): bool
    {
        $feature = $this->feature($tenant, $featureCode);

        return $feature !== null && filter_var($feature->value, FILTER_VALIDATE_BOOLEAN);
    }

    public function assertFeatureEnabled(Tenant $tenant, string $featureCode): void
    {
        if (! $this->hasFeature($tenant, $featureCode)) {
            throw new AuthorizationException('Fitur ini tidak tersedia pada paket langganan Anda.');
        }
    }

    public function limit(Tenant $tenant, string $limitCode): ?int
    {
        $feature = $this->feature($tenant, $limitCode);

        if ($feature !== null) {
            if ($feature->value === null || $feature->value === 'unlimited') {
                return null;
            }

            return max(0, (int) $feature->value);
        }

        // Keep existing tenant limits working until a plan explicitly defines
        // the equivalent entitlement.
        if ($limitCode === 'max_outlets' && $tenant->max_branches !== null) {
            return max(0, (int) $tenant->max_branches);
        }

        return null;
    }

    public function canCreateBranch(Tenant $tenant): bool
    {
        if (! $this->canWrite($tenant)) {
            return false;
        }

        $feature = $this->feature($tenant, 'max_outlets');
        $limit = $feature !== null
            ? $this->limit($tenant, 'max_outlets')
            : ($tenant->max_branches === null ? null : max(0, (int) $tenant->max_branches));

        if ($limit === null) {
            return true;
        }

        $branches = $tenant->branches();

        // Plan limits are explicitly outlet limits; workshops are separate
        // processing locations and do not consume an outlet seat.
        if ($feature !== null) {
            $branches->where('is_workshop', false);
        }

        return $branches->count() < $limit;
    }

    public function canCreateUser(Tenant $tenant): bool
    {
        if (! $this->canWrite($tenant)) {
            return false;
        }

        $limit = $this->limit($tenant, 'max_users');

        return $limit === null || $tenant->users()->where('role', '!=', 'super_admin')->count() < $limit;
    }

    public function assertCanCreateBranch(Tenant $tenant): void
    {
        if (! $this->canCreateBranch($tenant)) {
            throw ValidationException::withMessages([
                'data.name' => 'Kuota outlet/cabang pada paket Anda sudah penuh.',
            ]);
        }
    }

    public function assertCanCreateUser(Tenant $tenant): void
    {
        if (! $this->canCreateUser($tenant)) {
            throw ValidationException::withMessages([
                'data.email' => 'Kuota pengguna pada paket Anda sudah penuh.',
            ]);
        }
    }

    private function subscription(Tenant $tenant): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->with('plan.features')
            ->where('tenant_id', $tenant->getKey())
            ->first();
    }

    private function feature(Tenant $tenant, string $featureCode): ?PlanFeature
    {
        return $this->subscription($tenant)?->plan?->features
            ->firstWhere('feature_code', $featureCode);
    }

    private function periodIsCurrent(Subscription $subscription, $now): bool
    {
        $periodEnd = $subscription->current_period_end ?? $subscription->trial_ends_at;

        if ($periodEnd === null) {
            $legacyEnd = $subscription->tenant?->subscription_ends_at;

            return $legacyEnd === null || $now->lt($legacyEnd);
        }

        return $now->lt($periodEnd);
    }

    private function isWithinGracePeriod(Subscription $subscription, $now): bool
    {
        $periodEnd = $subscription->current_period_end;

        if ($periodEnd === null) {
            return false;
        }

        $graceEndsAt = $subscription->grace_ends_at
            ?? $periodEnd->copy()->addDays(app(SubscriptionService::class)->gracePeriodDays());

        return $now->lt($graceEndsAt);
    }
}
