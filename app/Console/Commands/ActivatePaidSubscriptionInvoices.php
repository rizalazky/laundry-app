<?php

namespace App\Console\Commands;

use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionInvoiceActivationService;
use Illuminate\Console\Command;

class ActivatePaidSubscriptionInvoices extends Command
{
    protected $signature = 'subscriptions:activate-paid-invoices';

    protected $description = 'Activate paid subscription invoices when their purchased period begins';

    public function handle(SubscriptionInvoiceActivationService $activation): int
    {
        SubscriptionInvoice::withoutGlobalScopes()
            ->where('status', 'paid')
            ->where('period_start', '<=', now())
            ->where('period_end', '>', now())
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($activation): void {
                foreach ($invoices as $invoice) {
                    $activation->activateIfDue($invoice);
                }
            });

        $this->info('Paid subscription invoices have been reconciled.');

        return self::SUCCESS;
    }
}
