<?php

namespace App\Actions\Accounting;

use App\Models\Journal;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;

class ReverseOrderRevenue
{
    public function handle(Order $order, CarbonInterface $cancelledAt, ?int $createdBy = null): ?Journal
    {
        $amount = BigDecimal::of((string) $order->total)->toScale(2);

        if ($amount->isLessThanOrEqualTo(0)) {
            return null;
        }

        return app(CreateJournal::class)->handle(
            tenantId: (int) $order->tenant_id,
            branchId: (int) $order->branch_id,
            journalDate: $cancelledAt,
            referenceType: 'order_cancellation',
            referenceId: (int) $order->getKey(),
            description: 'Pembatalan pengakuan pendapatan order '.$order->invoice_no,
            entries: [
                ['account_code' => '4000', 'debit' => (string) $amount, 'credit' => '0.00'],
                ['account_code' => '1100', 'debit' => '0.00', 'credit' => (string) $amount],
            ],
            createdBy: $createdBy,
        );
    }
}
