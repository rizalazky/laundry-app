<?php

namespace App\Console\Commands;

use App\Actions\Accounting\PostOrderRevenue;
use App\Actions\Accounting\PostPaymentToAccounting;
use App\Models\Order;
use Illuminate\Console\Command;

class BackfillOrderAccounting extends Command
{
    protected $signature = 'accounting:backfill-order-ledger {--tenant= : Limit the backfill to one tenant ID}';

    protected $description = 'Post existing active orders and their payments to the accounting ledger';

    public function handle(
        PostOrderRevenue $postOrderRevenue,
        PostPaymentToAccounting $postPaymentToAccounting,
    ): int {
        $ordersPosted = 0;
        $paymentsPosted = 0;

        $query = Order::withoutGlobalScope('tenant')
            ->where('status', '!=', 'cancelled')
            ->with(['payments' => fn ($payments) => $payments->withoutGlobalScope('tenant')->orderBy('id')])
            ->orderBy('id');

        if ($this->option('tenant') !== null) {
            $query->where('tenant_id', (int) $this->option('tenant'));
        }

        $query->chunkById(100, function ($orders) use (
            $postOrderRevenue,
            $postPaymentToAccounting,
            &$ordersPosted,
            &$paymentsPosted,
        ): void {
            foreach ($orders as $order) {
                $postOrderRevenue->handle($order);
                $ordersPosted++;

                foreach ($order->payments as $payment) {
                    $postPaymentToAccounting->handle($payment);
                    $paymentsPosted++;
                }
            }
        });

        $this->info("Accounting backfill completed: {$ordersPosted} order(s), {$paymentsPosted} payment(s).");

        return self::SUCCESS;
    }
}
