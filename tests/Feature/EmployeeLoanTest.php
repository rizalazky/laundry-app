<?php

namespace Tests\Feature;

use App\Actions\EmployeeLoans\CreateEmployeeLoan;
use App\Actions\EmployeeLoans\RecordEmployeeLoanRepayment;
use App\Filament\App\Resources\EmployeeLoans\EmployeeLoanResource;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeLoanTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_issue_loan_and_record_partial_and_final_repayments(): void
    {
        [$tenant, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $loan = app(CreateEmployeeLoan::class)->handle($owner, $employee->id, '1000.00', 'Keperluan mendesak');

        $this->assertSame($tenant->id, $loan->tenant_id);
        $this->assertSame($employee->id, $loan->user_id);
        $this->assertSame('1000.00', $loan->amount);
        $this->assertSame('1000.00', $loan->remaining_amount);
        $this->assertSame('active', $loan->status);

        $firstRepayment = app(RecordEmployeeLoanRepayment::class)->handle($owner, $loan->id, '250.00');
        $loan->refresh();

        $this->assertSame('250.00', $firstRepayment->amount);
        $this->assertSame($owner->id, $firstRepayment->recorded_by);
        $this->assertSame('750.00', $loan->remaining_amount);
        $this->assertSame('active', $loan->status);

        app(RecordEmployeeLoanRepayment::class)->handle($owner, $loan->id, '750.00');
        $loan->refresh();

        $this->assertSame('0.00', $loan->remaining_amount);
        $this->assertSame('paid', $loan->status);
        $this->assertDatabaseCount('employee_loan_repayments', 2);
    }

    public function test_repayment_cannot_exceed_remaining_balance_or_be_recorded_after_full_payment(): void
    {
        [, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $loan = app(CreateEmployeeLoan::class)->handle($owner, $employee->id, '500.00');

        try {
            app(RecordEmployeeLoanRepayment::class)->handle($owner, $loan->id, '500.01');
            $this->fail('Repayment above the remaining balance must be rejected.');
        } catch (DomainException) {
            $this->assertSame('500.00', $loan->fresh()->remaining_amount);
            $this->assertDatabaseCount('employee_loan_repayments', 0);
        }

        app(RecordEmployeeLoanRepayment::class)->handle($owner, $loan->id, '500.00');

        try {
            app(RecordEmployeeLoanRepayment::class)->handle($owner, $loan->id, '1.00');
            $this->fail('A fully paid loan must reject additional repayments.');
        } catch (DomainException) {
            $this->assertDatabaseCount('employee_loan_repayments', 1);
        }
    }

    public function test_employee_loan_is_tenant_scoped_and_rejects_foreign_employee(): void
    {
        [, $owner] = $this->context();
        [, , $foreignEmployee] = $this->context();
        $this->actingAs($owner);

        try {
            app(CreateEmployeeLoan::class)->handle($owner, $foreignEmployee->id, '100.00');
            $this->fail('An employee from another tenant must be rejected.');
        } catch (DomainException) {
            $this->assertDatabaseCount('employee_loans', 0);
        }
    }

    public function test_branch_manager_needs_staff_manage_and_can_only_issue_to_assigned_branch_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $assignedEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $assignedEmployee->branches()->attach($branch);

        $outsideEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $outsideEmployee->branches()->attach($otherBranch);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->actingAs($manager);

        $this->assertTrue(EmployeeLoanResource::canAccess());
        $this->assertFalse(EmployeeLoanResource::canManage());

        try {
            app(CreateEmployeeLoan::class)->handle($manager, $assignedEmployee->id, '100.00');
            $this->fail('Viewing staff does not grant permission to manage loans.');
        } catch (DomainException) {
            $this->assertDatabaseCount('employee_loans', 0);
        }

        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $loan = app(CreateEmployeeLoan::class)->handle($manager, $assignedEmployee->id, '100.00');
        $this->assertSame($assignedEmployee->id, $loan->user_id);

        try {
            app(CreateEmployeeLoan::class)->handle($manager, $outsideEmployee->id, '100.00');
            $this->fail('A branch manager cannot issue loans outside assigned branches.');
        } catch (DomainException) {
            $this->assertDatabaseCount('employee_loans', 1);
        }
    }

    public function test_inactive_employee_cannot_receive_a_new_loan(): void
    {
        [, $owner, $employee] = $this->context();
        $employee->forceFill(['is_active' => false])->save();
        $this->actingAs($owner);

        try {
            app(CreateEmployeeLoan::class)->handle($owner, $employee->id, '100.00');
            $this->fail('Inactive employees cannot receive new loans.');
        } catch (DomainException) {
            $this->assertDatabaseCount('employee_loans', 0);
        }
    }

    public function test_resource_query_only_returns_current_tenant_loans(): void
    {
        [, $owner, $employee] = $this->context();
        [, $foreignOwner, $foreignEmployee] = $this->context();

        $this->actingAs($owner);
        $loan = app(CreateEmployeeLoan::class)->handle($owner, $employee->id, '100.00');

        $this->actingAs($foreignOwner);
        app(CreateEmployeeLoan::class)->handle($foreignOwner, $foreignEmployee->id, '200.00');

        $this->actingAs($owner);

        $this->assertSame(
            [$loan->id],
            EmployeeLoanResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $employee = User::factory()->for($tenant)->withRole('cashier')->create();

        return [$tenant, $owner, $employee];
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Karyawan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
