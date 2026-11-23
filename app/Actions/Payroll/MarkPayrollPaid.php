<?php

namespace App\Actions\Payroll;

use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanRepayment;
use App\Models\Payroll;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class MarkPayrollPaid
{
    public function __construct(private PayrollAuthorization $authorization) {}

    public function handle(User $actor, int $payrollId): Payroll
    {
        return DB::transaction(function () use ($actor, $payrollId): Payroll {
            $this->authorization->authorizeManager($actor);

            $payroll = Payroll::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($payrollId)
                ->lockForUpdate()
                ->first();

            if ($payroll === null) {
                throw new DomainException('Data payroll tidak ditemukan.');
            }

            $this->authorization->authorizeEmployee($actor, (int) $payroll->user_id, false);

            if ($payroll->status !== 'draft') {
                throw new DomainException('Hanya payroll draft yang dapat ditandai sudah dibayar.');
            }

            $remainingDeduction = $this->toCents($payroll->loan_deduction);

            $loans = EmployeeLoan::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('user_id', $payroll->user_id)
                ->where('status', 'active')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($loans as $loan) {
                if ($remainingDeduction <= 0) {
                    break;
                }

                $loanRemaining = $this->toCents($loan->remaining_amount);
                $repaymentAmount = min($remainingDeduction, $loanRemaining);

                if ($repaymentAmount <= 0) {
                    continue;
                }

                $newRemaining = $loanRemaining - $repaymentAmount;

                EmployeeLoanRepayment::query()->create([
                    'tenant_id' => $actor->tenant_id,
                    'employee_loan_id' => $loan->getKey(),
                    'payroll_id' => $payroll->getKey(),
                    'recorded_by' => $actor->getKey(),
                    'amount' => $this->fromCents($repaymentAmount),
                    'paid_at' => now(),
                    'notes' => 'Potongan kasbon payroll '.$payroll->period_month,
                ]);

                $loan->forceFill([
                    'remaining_amount' => $this->fromCents($newRemaining),
                    'status' => $newRemaining === 0 ? 'paid' : 'active',
                ])->save();

                $remainingDeduction -= $repaymentAmount;
            }

            if ($remainingDeduction > 0) {
                throw new DomainException('Sisa kasbon sudah berubah. Perbarui payroll sebelum menandai pembayaran.');
            }

            $payroll->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            return $payroll->fresh(['employee', 'loanRepayments']);
        });
    }

    private function toCents(string|int|float $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new DomainException('Nominal payroll tidak valid.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
