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

            $paymentInCents = $this->toCents($amount);
            $remainingInCents = $this->toCents($loan->remaining_amount);

            if ($paymentInCents <= 0 || $paymentInCents > $remainingInCents) {
                throw new DomainException('Pembayaran harus lebih dari nol dan tidak boleh melebihi sisa kasbon.');
            }

            $newRemainingInCents = $remainingInCents - $paymentInCents;

            $repayment = EmployeeLoanRepayment::query()->create([
                'tenant_id' => $actor->tenant_id,
                'employee_loan_id' => $loan->getKey(),
                'recorded_by' => $actor->getKey(),
                'amount' => $this->fromCents($paymentInCents),
                'paid_at' => $paidAt ?? now(),
                'notes' => $notes,
            ]);

            $loan->forceFill([
                'remaining_amount' => $this->fromCents($newRemainingInCents),
                'status' => $newRemainingInCents === 0 ? 'paid' : 'active',
            ])->save();

            return $repayment;
        });
    }

    private function toCents(string|int|float $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new DomainException('Nominal pembayaran harus berupa angka dengan maksimal dua angka desimal.');
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');

        return ($whole * 100) + $fraction;
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
