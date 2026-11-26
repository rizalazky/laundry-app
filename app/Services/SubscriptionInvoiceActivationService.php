<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class SubscriptionInvoiceActivationService
{
    public function activateIfDue(SubscriptionInvoice|int $invoice): string
    {
        $invoiceId = $invoice instanceof SubscriptionInvoice ? (int) $invoice->getKey() : $invoice;

        return DB::transaction(function () use ($invoiceId): string {
            $lockedInvoice = SubscriptionInvoice::withoutGlobalScopes()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status !== 'paid') {
                return 'not_paid';
            }

            if ($lockedInvoice->period_start->isFuture() && $lockedInvoice->type !== 'initial') {
                return 'scheduled';
            }

            if (! $lockedInvoice->period_end->isFuture()) {
                return 'period_expired';
            }

            $subscription = Subscription::withoutGlobalScopes()
                ->whereKey($lockedInvoice->subscription_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $subscription->plan_id === (int) $lockedInvoice->plan_id
                && $subscription->current_period_end?->equalTo($lockedInvoice->period_end)
                && $subscription->status === 'active'
                && ($lockedInvoice->type === 'upgrade'
                    || $subscription->current_period_start?->equalTo($lockedInvoice->period_start))
            ) {
                return 'already_activated';
            }

            if ($lockedInvoice->type === 'upgrade') {
                if (
                    $subscription->current_period_end === null
                    || ! $subscription->current_period_end->equalTo($lockedInvoice->period_end)
                    || ! $subscription->current_period_end->isFuture()
                ) {
                    return 'stale_upgrade';
                }
            } elseif (
                $subscription->current_period_end !== null
                && $lockedInvoice->period_start->lt($subscription->current_period_end)
            ) {
                return 'period_conflict';
            }

            if ($lockedInvoice->type === 'upgrade') {
                // An upgrade changes entitlements immediately but preserves the
                // original billing period boundaries.
                $subscription->forceFill([
                    'plan_id' => $lockedInvoice->plan_id ?? $subscription->plan_id,
                    'status' => 'active',
                    'grace_ends_at' => null,
                ])->save();

                return 'activated';
            }

            $subscription->forceFill([
                'plan_id' => $lockedInvoice->plan_id ?? $subscription->plan_id,
                'status' => 'active',
                'current_period_start' => $lockedInvoice->period_start,
                'current_period_end' => $lockedInvoice->period_end,
                'grace_ends_at' => null,
            ])->save();

            $tenant = Tenant::withoutGlobalScopes()
                ->whereKey($lockedInvoice->tenant_id)
                ->lockForUpdate()
                ->first();

            if ($tenant !== null) {
                $tenant->forceFill([
                    'subscription_ends_at' => $lockedInvoice->period_end,
                ])->save();
            }

            return 'activated';
        });
    }
}
