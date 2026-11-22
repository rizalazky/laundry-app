<?php

namespace App\Actions\Accounting;

use App\Enums\PaymentType;
use App\Models\Journal;
use App\Models\Payment;
use Brick\Math\BigDecimal;

class PostPaymentToAccounting
{
    public function handle(Payment $payment): Journal
    {
        $assetCode = match ($payment->method) {
            \App\Enums\PaymentMethod::Cash => '1000',
            \App\Enums\PaymentMethod::Transfer => '1010',
            \App\Enums\PaymentMethod::Qris => '1020',
            \App\Enums\PaymentMethod::EdcDebit,
            \App\Enums\PaymentMethod::EdcCredit => '1030',
        };

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
            branchId: (int) $payment->branch_id,
            journalDate: $payment->paid_at,
            referenceType: 'payment',
            referenceId: (int) $payment->getKey(),
            description: ($isRefund ? 'Pengembalian pembayaran ' : 'Penerimaan pembayaran ')
                .'order '.$payment->order?->invoice_no,
            entries: $entries,
            createdBy: $payment->received_by !== null ? (int) $payment->received_by : null,
        );
    }
}
