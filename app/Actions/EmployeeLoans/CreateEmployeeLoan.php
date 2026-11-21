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
            $this->authorization->authorizeManager($actor);
            $employee = $this->authorization->authorizeEmployee(
                $actor,
                new User(['id' => $employeeId]),
            );
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
        if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 9999999999999.99) {
            throw new DomainException('Nominal kasbon tidak valid.');
        }

        return (int) round((float) $amount * 100);
    }

    private function fromCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
