<?php

namespace App\Actions\Accounting;

use App\Models\Journal;
use App\Models\Order;
use Brick\Math\BigDecimal;

class PostOrderRevenue
{
    public function handle(Order $order): ?Journal
    {
        $amount = BigDecimal::of((string) $order->total)->toScale(2);

        if ($amount->isLessThanOrEqualTo(0)) {
            return null;
        }

        return app(CreateJournal::class)->handle(
            tenantId: (int) $order->tenant_id,
            branchId: (int) $order->branch_id,
            journalDate: $order->order_date,
            referenceType: 'order',
            referenceId: (int) $order->getKey(),
            description: 'Pengakuan pendapatan order '.$order->invoice_no,
            entries: [
                ['account_code' => '1100', 'debit' => (string) $amount, 'credit' => '0.00'],
                ['account_code' => '4000', 'debit' => '0.00', 'credit' => (string) $amount],
            ],
            createdBy: $order->created_by !== null ? (int) $order->created_by : null,
        );
    }
}
