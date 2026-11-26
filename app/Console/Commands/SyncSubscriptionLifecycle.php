<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionEventRecorder;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSubscriptionLifecycle extends Command
{
    protected $signature = 'subscriptions:sync-lifecycle';

    protected $description = 'Transition expired trials and paid subscriptions through grace period';

    public function handle(
        SubscriptionService $subscriptions,
        SubscriptionEventRecorder $events,
    ): int {
        $now = now();
        $graceDays = $subscriptions->gracePeriodDays();

        Subscription::withoutGlobalScopes()
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($now, $graceDays, $events): void {
                foreach ($records as $record) {
                    DB::transaction(function () use ($record, $now, $graceDays, $events): void {
                        $subscription = Subscription::withoutGlobalScopes()
                            ->whereKey($record->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($subscription === null) {
                            return;
                        }

                        if (
                            $subscription->status === 'trialing'
                            && $subscription->trial_ends_at !== null
                            && ! $subscription->trial_ends_at->isFuture()
                        ) {
                            $fromStatus = $subscription->status;
                            $subscription->forceFill([
                                'status' => 'expired',
                                'grace_ends_at' => null,
                            ])->save();

                            $events->record($subscription, 'trial_expired', [
                                'from_status' => $fromStatus,
                                'to_status' => 'expired',
                                'metadata' => [
                                    'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
                                ],
                                'occurred_at' => $now,
                            ]);

                            return;
                        }

                        if ($subscription->status === 'active') {
                            $periodEnd = $subscription->current_period_end;

                            if ($periodEnd === null || $periodEnd->isFuture()) {
                                return;
                            }

                            $graceEndsAt = $subscription->grace_ends_at
                                ?? $periodEnd->copy()->addDays($graceDays);

                            $fromStatus = $subscription->status;
                            $nextStatus = $graceEndsAt->isFuture() ? 'past_due' : 'expired';

                            $subscription->forceFill([
                                'status' => $nextStatus,
                                'grace_ends_at' => $graceEndsAt,
                            ])->save();

                            $events->record(
                                $subscription,
                                $nextStatus === 'past_due' ? 'grace_started' : 'subscription_expired',
                                [
                                    'from_status' => $fromStatus,
                                    'to_status' => $nextStatus,
                                    'metadata' => [
                                        'period_ended_at' => $periodEnd->toIso8601String(),
                                        'grace_ends_at' => $graceEndsAt->toIso8601String(),
                                        'grace_period_days' => $graceDays,
                                    ],
                                    'occurred_at' => $now,
                                ],
                            );

                            return;
                        }

                        if ($subscription->status === 'past_due') {
                            $periodEnd = $subscription->current_period_end;

                            if ($periodEnd === null) {
                                return;
                            }

                            $graceEndsAt = $subscription->grace_ends_at
                                ?? $periodEnd->copy()->addDays($graceDays);

                            if ($subscription->grace_ends_at === null) {
                                $subscription->forceFill(['grace_ends_at' => $graceEndsAt])->save();
                            }

                            if (! $graceEndsAt->isFuture()) {
                                $subscription->forceFill(['status' => 'expired'])->save();

                                $events->record($subscription, 'subscription_expired', [
                                    'from_status' => 'past_due',
                                    'to_status' => 'expired',
                                    'metadata' => [
                                        'period_ended_at' => $periodEnd->toIso8601String(),
                                        'grace_ends_at' => $graceEndsAt->toIso8601String(),
                                        'grace_period_days' => $graceDays,
                                    ],
                                    'occurred_at' => $now,
                                ]);
                            }
                        }
                    });
                }
            });

        $this->info('Subscription lifecycle synchronized.');

        return self::SUCCESS;
    }
}
