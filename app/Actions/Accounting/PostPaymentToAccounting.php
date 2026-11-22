<?php

namespace App\Actions\Accounting;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\ChartOfAccount;
use App\Models\Order;
use DomainException;
use App\Models\Journal;
use App\Models\Payment;
use Brick\Math\BigDecimal;

class PostPaymentToAccounting
{
    public function handle(Payment $payment): Journal
    {
        app(EnsureDefaultChartOfAccounts::class)->handle((int) $payment->tenant_id);

        $assetCode = match ($payment->method) {
            PaymentMethod::Cash => '1000',
            PaymentMethod::Transfer => '1010',
            PaymentMethod::Qris => '1020',
            PaymentMethod::EdcDebit,
            PaymentMethod::EdcCredit => '1030',
        };

        $order = Order::withoutGlobalScope('tenant')
            ->where('tenant_id', $payment->tenant_id)
            ->findOrFail($payment->order_id);

        $accounts = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $payment->tenant_id)
            ->whereIn('code', [$assetCode, '1100'])
            ->get()
            ->keyBy('code');

        if (
            $accounts->count() !== 2
            || $accounts->get($assetCode)?->account_type !== 'asset'
            || $accounts->get('1100')?->account_type !== 'asset'
        ) {
            throw new DomainException('Akun penerimaan pembayaran dan Piutang Usaha harus tersedia serta bertipe aset.');
        }

        $amount = BigDecimal::of((string) $payment->amount)->toScale(2);
        $isRefund = $payment->type === PaymentType::Refund;

        $entries = $isRefund
            ? [
                ['account_code' => '1100', 'debit' => (string) $amount, 'credit' => '0.00'],
                ['account_code' => $assetCode, 'debit' => '0.00', 'credit' => (string) $amount],
            ]
            : [
                ['account_code' => $assetCode, 'debit' => (string) $amount, 'credit' => '0.00'],
                ['account_code' => '1100', 'debit' => '0.00', 'credit' => (string) $amount],
            ];

        return app(CreateJournal::class)->handle(
            tenantId: (int) $payment->tenant_id,
            branchId: $payment->branch_id !== null ? (int) $payment->branch_id : null,
            journalDate: $payment->paid_at,
            referenceType: 'payment',
            referenceId: (int) $payment->getKey(),
            description: ($isRefund ? 'Pengembalian pembayaran ' : 'Penerimaan pembayaran ')
                .'order '.$order->invoice_no,
            entries: $entries,
            createdBy: $payment->received_by !== null ? (int) $payment->received_by : null,
        );
    }
}
