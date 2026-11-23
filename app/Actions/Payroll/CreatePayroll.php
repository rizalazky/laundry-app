<?php

namespace App\Actions\Payroll;

use App\Models\CommissionLog;
use App\Models\EmployeeLoan;
use App\Models\Payroll;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreatePayroll
{
    public function __construct(private PayrollAuthorization $authorization) {}

    public function handle(
        User $actor,
        int $employeeId,
        string $periodMonth,
        string|int|float $loanDeduction = '0',
    ): Payroll {
        return DB::transaction(function () use ($actor, $employeeId, $periodMonth, $loanDeduction): Payroll {
            $this->authorization->authorizeManager($actor);
            $employee = $this->authorization->authorizeEmployee($actor, $employeeId);

            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodMonth)) {
                throw new DomainException('Periode payroll harus menggunakan format YYYY-MM.');
            }

            $periodStart = CarbonImmutable::createFromFormat('!Y-m', $periodMonth);

            if ($periodStart === false || $periodStart->format('Y-m') !== $periodMonth || $periodMonth > now()->format('Y-m')) {
                throw new DomainException('Periode payroll tidak valid atau belum berjalan.');
            }

            if (Payroll::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('user_id', $employee->getKey())
                ->where('period_month', $periodMonth)
                ->exists()) {
                throw new DomainException('Payroll untuk karyawan dan periode ini sudah dibuat.');
            }

            $baseSalary = $this->toCents($employee->monthly_base_salary ?? '0');
            $periodEnd = $periodStart->endOfMonth();

            $commission = CommissionLog::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('user_id', $employee->getKey())
                ->whereBetween('earned_at', [$periodStart, $periodEnd])
                ->sum('amount');

            $commissionCents = $this->toCents((string) $commission);
            $deductionCents = $this->toCents($loanDeduction);

            if ($deductionCents < 0) {
                throw new DomainException('Potongan kasbon tidak boleh bernilai negatif.');
            }

            $outstandingCents = 0;
            $loans = EmployeeLoan::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('user_id', $employee->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->get(['remaining_amount']);

            foreach ($loans as $loan) {
                $outstandingCents += $this->toCents($loan->remaining_amount);
            }

            if ($deductionCents > $outstandingCents) {
                throw new DomainException('Potongan kasbon melebihi total sisa kasbon karyawan.');
            }

            $netSalaryCents = $baseSalary + $commissionCents - $deductionCents;

            if ($netSalaryCents < 0) {
                throw new DomainException('Gaji bersih tidak boleh bernilai negatif.');
            }

            return Payroll::query()->create([
                'tenant_id' => $actor->tenant_id,
                'user_id' => $employee->getKey(),
                'created_by' => $actor->getKey(),
                'period_month' => $periodMonth,
                'base_salary' => $this->fromCents($baseSalary),
                'commission_total' => $this->fromCents($commissionCents),
                'loan_deduction' => $this->fromCents($deductionCents),
                'net_salary' => $this->fromCents($netSalaryCents),
                'status' => 'draft',
            ]);
        });
    }

    private function toCents(string|int|float $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new DomainException('Nominal payroll harus berupa angka dengan maksimal dua angka desimal.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
