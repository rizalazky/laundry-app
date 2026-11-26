<?php

namespace App\\Console\\Commands;

use App\\Models\\SubscriptionPayment;
use App\\Services\\SubscriptionPaymentReconciliationService;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\Log;
use Throwable;

class ReconcilePendingSubscriptionPayments extends Command
{
    protected $signature = 'subscriptions:reconcile-pending-payments';

    protected $description = 'Reconcile pending subscription payments against the payment provider';

    public function handle(SubscriptionPaymentReconciliationService $reconciliation): int
    {
        $checked = 0;
        $failed = 0;

        SubscriptionPayment::withoutGlobalScopes()
            ->where('provider', (string) config('services.payment_gateway.driver'))
            ->where('status', 'pending')
            ->whereNotNull('provider_reference')
            ->where('provider_reference', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($payments) use ($reconciliation, &$checked, &$failed): void {
                foreach ($payments as $payment) {
                    $checked++;

                    try {
                        $reconciliation->handle([
                            'order_id' => $payment->provider_reference,
                        ]);
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::warning('Subscription payment reconciliation failed.', [
                            'payment_id' => $payment->getKey(),
                            'provider' => $payment->provider,
                            'provider_reference' => $payment->provider_reference,
                            'exception' => class_basename($exception),
                        ]);
                    }
                }
            });

        $this->info("Reconciled {$checked} pending subscription payment(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
