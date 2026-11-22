<?php

namespace App\Actions\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use DomainException;

class ReverseOrderRevenue
{
    public function handle(Order $order, CarbonInterface $cancelledAt, ?int $createdBy = null): ?Journal
    {
        app(EnsureDefaultChartOfAccounts::class)->handle((int) $order->tenant_id);

        $amount = BigDecimal::of((string) $order->total)->toScale(2);

        if ($amount->isLessThanOrEqualTo(0)) {
            return null;
        }

        $accounts = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $order->tenant_id)
            ->whereIn('code', ['1100', '4000'])
            ->get()
            ->keyBy('code');

        if (
            $accounts->count() !== 2
            || $accounts->get('1100')?->account_type !== 'asset'
            || $accounts->get('4000')?->account_type !== 'revenue'
        ) {
            throw new DomainException('Akun Piutang Usaha (1100) dan Pendapatan Jasa Laundry (4000) harus tersedia dengan tipe akun yang benar.');
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
