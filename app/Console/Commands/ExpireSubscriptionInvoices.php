<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireSubscriptionInvoices extends Command
{
    protected $signature = 'subscriptions:expire-invoices';

    protected $description = 'Expire unpaid subscription invoices after their 24-hour validity period';

    public function handle(): int
    {
        $expired = 0;

        SubscriptionInvoice::withoutGlobalScopes()
            ->whereIn('status', ['issued', 'pending'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use (&$expired): void {
                foreach ($invoices as $invoice) {
                    DB::transaction(function () use ($invoice, &$expired): void {
                        $lockedInvoice = SubscriptionInvoice::withoutGlobalScopes()
                            ->whereKey($invoice->getKey())
                            ->lockForUpdate()
                            ->first();

                        if (
                            $lockedInvoice === null
                            || ! in_array($lockedInvoice->status, ['issued', 'pending'], true)
                            || $lockedInvoice->due_at === null
                            || $lockedInvoice->due_at->isFuture()
                        ) {
                            return;
                        }

                        $lockedInvoice->forceFill(['status' => 'expired'])->save();

                        $subscription = Subscription::withoutGlobalScopes()
                            ->whereKey($lockedInvoice->subscription_id)
                            ->first();

                        if ($subscription !== null) {
                            app(SubscriptionEventRecorder::class)->record($subscription, 'invoice_expired', [
                                'invoice_id' => $lockedInvoice->getKey(),
                                'metadata' => [
                                    'invoice_number' => $lockedInvoice->invoice_number,
                                    'due_at' => $lockedInvoice->due_at->toIso8601String(),
                                    'total_amount' => $lockedInvoice->total_amount,
                                ],
                                'occurred_at' => now(),
                            ]);
                        }

                        $expired++;
                    });
                }
            });

        $this->info("Expired {$expired} subscription invoice(s).");

        return self::SUCCESS;
    }
}
