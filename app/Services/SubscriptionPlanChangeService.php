<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionPlanChangeService
{
    public function scheduleChange(Tenant $tenant, Plan $plan): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan): Subscription {
            $this->assertOwner($tenant);

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->current_period_end === null || $subscription->current_period_end->isPast()) {
                throw ValidationException::withMessages([
                    'plan' => 'Perubahan paket terjadwal hanya tersedia untuk subscription dengan periode aktif.',
                ]);
            }

            $selectedPlan = Plan::query()->findOrFail($plan->getKey());

            if (! $selectedPlan->is_active) {
                throw ValidationException::withMessages([
                    'plan' => 'Paket tujuan tidak tersedia.',
                ]);
            }

            if ((int) $selectedPlan->getKey() === (int) $subscription->plan_id) {
                throw ValidationException::withMessages([
                    'plan' => 'Paket tersebut sudah menjadi paket saat ini.',
                ]);
            }

            $hasOpenInvoice = SubscriptionInvoice::withoutGlobalScopes()
                ->where('subscription_id', $subscription->getKey())
                ->whereIn('status', ['issued', 'pending'])
                ->exists();

            if ($hasOpenInvoice) {
                throw ValidationException::withMessages([
                    'plan' => 'Selesaikan atau tunggu invoice yang masih aktif sebelum menjadwalkan perubahan paket.',
                ]);
            }

            $subscription->forceFill([
                'scheduled_plan_id' => $selectedPlan->getKey(),
                'scheduled_change_at' => $subscription->current_period_end,
            ])->save();

            return $subscription->refresh()->load(['plan', 'scheduledPlan']);
        });
    }

    public function cancelScheduledChange(Tenant $tenant): Subscription
    {
        return DB::transaction(function () use ($tenant): Subscription {
            $this->assertOwner($tenant);

            $subscription = Subscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $subscription->forceFill([
                'scheduled_plan_id' => null,
                'scheduled_change_at' => null,
            ])->save();

            return $subscription->refresh();
        });
    }

    public function applyDueChanges(): int
    {
        $applied = 0;

        Subscription::withoutGlobalScopes()
            ->whereNotNull('scheduled_plan_id')
            ->whereNotNull('scheduled_change_at')
            ->where('scheduled_change_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $subscriptionId) use (&$applied): void {
                $changed = DB::transaction(function () use ($subscriptionId): bool {
                    $subscription = Subscription::withoutGlobalScopes()
                        ->whereKey($subscriptionId)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $subscription === null
                        || $subscription->scheduled_plan_id === null
                        || $subscription->scheduled_change_at === null
                        || $subscription->scheduled_change_at->isFuture()
                    ) {
                        return false;
                    }

                    $subscription->forceFill([
                        'plan_id' => $subscription->scheduled_plan_id,
                        'scheduled_plan_id' => null,
                        'scheduled_change_at' => null,
                    ])->save();

                    return true;
                });

                if ($changed) {
                    $applied++;
                }
            });

        return $applied;
    }

    private function assertOwner(Tenant $tenant): void
    {
        $user = Auth::user();

        if (
            ! $user instanceof User
            || ! $user->is_active
            || $user->role !== 'tenant_owner'
            || (int) $user->tenant_id !== (int) $tenant->getKey()
        ) {
            throw new AuthorizationException('Hanya owner tenant yang dapat mengubah paket subscription.');
        }
    }
}
