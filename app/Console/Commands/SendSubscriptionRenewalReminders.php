<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionRenewalReminder;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminderNotification;
use App\Services\SubscriptionEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendSubscriptionRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Send in-app subscription renewal reminders at H-7, H-3, and H-1';

    public function handle(SubscriptionEventRecorder $events): int
    {
        $sent = 0;
        $now = now();

        foreach ([7, 3, 1] as $daysRemaining) {
            $targetStart = $now->copy()->addDays($daysRemaining)->startOfDay();
            $targetEnd = $targetStart->copy()->endOfDay();

            Subscription::withoutGlobalScopes()
                ->where('status', 'active')
                ->whereBetween('current_period_end', [$targetStart, $targetEnd])
                ->orderBy('id')
                ->chunkById(100, function ($subscriptions) use (
                    $daysRemaining,
                    $targetStart,
                    $targetEnd,
                    $events,
                    &$sent,
                ): void {
                    foreach ($subscriptions as $record) {
                        DB::transaction(function () use (
                            $record,
                            $daysRemaining,
                            $targetStart,
                            $targetEnd,
                            $events,
                            &$sent,
                        ): void {
                            $subscription = Subscription::withoutGlobalScopes()
                                ->whereKey($record->getKey())
                                ->lockForUpdate()
                                ->first();

                            if (
                                $subscription === null
                                || $subscription->status !== 'active'
                                || $subscription->current_period_end === null
                                || $subscription->current_period_end->lt($targetStart)
                                || $subscription->current_period_end->gt($targetEnd)
                            ) {
                                return;
                            }

                            $periodEnd = $subscription->current_period_end;

                            $hasPaidRenewal = SubscriptionInvoice::withoutGlobalScopes()
                                ->where('subscription_id', $subscription->getKey())
                                ->where('status', 'paid')
                                ->where('type', 'renewal')
                                ->where('period_start', '<=', $periodEnd)
                                ->where('period_end', '>', $periodEnd)
                                ->exists();

                            if ($hasPaidRenewal) {
                                return;
                            }

                            $owners = User::query()
                                ->where('tenant_id', $subscription->tenant_id)
                                ->where('role', 'tenant_owner')
                                ->where('is_active', true)
                                ->get();

                            if ($owners->isEmpty()) {
                                return;
                            }

                            $reminder = SubscriptionRenewalReminder::query()->firstOrCreate(
                                [
                                    'subscription_id' => $subscription->getKey(),
                                    'period_end' => $periodEnd,
                                    'days_before_expiry' => $daysRemaining,
                                ],
                                [
                                    'tenant_id' => $subscription->tenant_id,
                                    'sent_at' => now(),
                                ],
                            );

                            if (! $reminder->wasRecentlyCreated) {
                                return;
                            }

                            $subscription->loadMissing('plan');

                            foreach ($owners as $owner) {
                                $owner->notify(new SubscriptionRenewalReminderNotification(
                                    $subscription,
                                    $daysRemaining,
                                ));
                            }

                            $events->record($subscription, 'subscription_renewal_reminder_sent', [
                                'metadata' => [
                                    'days_remaining' => $daysRemaining,
                                    'period_end' => $periodEnd->toIso8601String(),
                                    'recipient_count' => $owners->count(),
                                ],
                                'occurred_at' => now(),
                            ]);

                            $sent++;
                        });
                    }
                });
        }

        $this->info("Sent {$sent} subscription renewal reminder(s).");

        return self::SUCCESS;
    }
}
