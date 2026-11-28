<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollEmployeeLoanTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_loan_cannot_reference_an_employee_from_another_tenant(): void
    {
        [$tenantA, $ownerA] = $this->tenantContext();
        [, , $employeeB] = $this->tenantContext();

        $this->expectException(QueryException::class);

        $this->insertLoan($tenantA, $employeeB, $ownerA);
    }

    public function test_employee_loan_creator_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, , $employeeA] = $this->tenantContext();
        [, $ownerB] = $this->tenantContext();

        $this->expectException(QueryException::class);

        $this->insertLoan($tenantA, $employeeA, $ownerB);
    }

    public function test_employee_loan_repayment_recorder_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, $ownerA, $employeeA] = $this->tenantContext();
        [, $ownerB] = $this->tenantContext();
        $loanId = $this->insertLoan($tenantA, $employeeA, $ownerA);

        $this->expectException(QueryException::class);

        DB::table('employee_loan_repayments')->insert([
            'tenant_id' => $tenantA->getKey(),
            'employee_loan_id' => $loanId,
            'recorded_by' => $ownerB->getKey(),
            'amount' => '10.00',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payroll_cannot_reference_an_employee_from_another_tenant(): void
    {
        [$tenantA, $ownerA] = $this->tenantContext();
        [, , $employeeB] = $this->tenantContext();

        $this->expectException(QueryException::class);

        $this->insertPayroll($tenantA, $employeeB, $ownerA);
    }

    public function test_payroll_creator_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, , $employeeA] = $this->tenantContext();
        [, $ownerB] = $this->tenantContext();

        $this->expectException(QueryException::class);

        $this->insertPayroll($tenantA, $employeeA, $ownerB);
    }

    private function tenantContext(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $employee = User::factory()->for($tenant)->withRole('cashier')->create();

        return [$tenant, $owner, $employee];
    }

    private function insertLoan(Tenant $tenant, User $employee, User $creator): int
    {
        return (int) DB::table('employee_loans')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $employee->getKey(),
            'created_by' => $creator->getKey(),
            'amount' => '100.00',
            'remaining_amount' => '100.00',
            'status' => 'active',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPayroll(Tenant $tenant, User $employee, User $creator): int
    {
        return (int) DB::table('payrolls')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $employee->getKey(),
            'created_by' => $creator->getKey(),
            'period_month' => '2026-11',
            'base_salary' => '100.00',
            'commission_total' => '0.00',
            'loan_deduction' => '0.00',
            'net_salary' => '100.00',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
