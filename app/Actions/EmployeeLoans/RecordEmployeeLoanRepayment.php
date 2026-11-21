<?php

namespace App\Actions\EmployeeLoans;

use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanRepayment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordEmployeeLoanRepayment
{
    public function __construct(private EmployeeLoanAuthorization $authorization) {}

    public function handle(
        User $actor,
        int $loanId,
        string|int|float $amount,
        ?Carbon $paidAt = null,
        ?string $notes = null,
    ): EmployeeLoanRepayment {
        return DB::transaction(function () use ($actor, $loanId, $amount, $paidAt, $notes): EmployeeLoanRepayment {
            $this->authorization->authorizeManager($actor);

            $loan = EmployeeLoan::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($loanId)
                ->lockForUpdate()
                ->first();

            if ($loan === null) {
                throw new DomainException('Data kasbon tidak ditemukan.');
            }

            $this->authorization->authorizeEmployee($actor, $loan->user, false);

            if ($loan->status !== 'active') {
                throw new DomainException('Kasbon ini sudah lunas dan tidak menerima pembayaran lagi.');
            }

            if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 9999999999999.99) {
                throw new DomainException('Nominal pembayaran tidak valid.');
            }

            $paymentInCents = (int) round((float) $amount * 100);
            $remainingInCents = (int) round((float) $loan->remaining_amount * 100);

            if ($paymentInCents <= 0 || $paymentInCents > $remainingInCents) {
                throw new DomainException('Pembayaran tidak boleh melebihi sisa kasbon.');
            }

            $newRemainingInCents = $remainingInCents - $paymentInCents;

            $repayment = EmployeeLoanRepayment::query()->create([
                'tenant_id' => $actor->tenant_id,
                'employee_loan_id' => $loan->getKey(),
                'recorded_by' => $actor->getKey(),
                'amount' => number_format($paymentInCents / 100, 2, '.', ''),
                'paid_at' => $paidAt ?? now(),
                'notes' => $notes,
            ]);

            $loan->forceFill([
                'remaining_amount' => number_format($newRemainingInCents / 100, 2, '.', ''),
                'status' => $newRemainingInCents === 0 ? 'paid' : 'active',
            ])->save();

            return $repayment;
        });
    }
}
