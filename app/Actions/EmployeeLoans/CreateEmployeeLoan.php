<?php

namespace App\Actions\EmployeeLoans;

use App\Models\EmployeeLoan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateEmployeeLoan
{
    public function __construct(private EmployeeLoanAuthorization $authorization) {}

    public function handle(User $actor, int $employeeId, string|int|float $amount, ?string $notes = null): EmployeeLoan
    {
        return DB::transaction(function () use ($actor, $employeeId, $amount, $notes): EmployeeLoan {
            $actor = $this->authorization->authorizeManager($actor);

            $employee = User::query()->find($employeeId);

            if ($employee === null) {
                throw new DomainException('Karyawan tidak ditemukan.');
            }

            $employee = $this->authorization->authorizeEmployee($actor, $employee);
            $amountInCents = $this->toCents($amount);

            if ($amountInCents <= 0) {
                throw new DomainException('Nominal kasbon harus lebih besar dari nol.');
            }

            $formattedAmount = $this->fromCents($amountInCents);

            return EmployeeLoan::query()->create([
                'tenant_id' => $actor->tenant_id,
                'user_id' => $employee->getKey(),
                'created_by' => $actor->getKey(),
                'amount' => $formattedAmount,
                'remaining_amount' => $formattedAmount,
                'status' => 'active',
                'issued_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    private function toCents(string|int|float $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new DomainException('Nominal kasbon harus berupa angka dengan maksimal dua angka desimal.');
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
