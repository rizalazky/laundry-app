<?php

namespace Tests\Feature;

use App\Actions\EmployeeLoans\CreateEmployeeLoan;
use App\Actions\EmployeeLoans\RecordEmployeeLoanRepayment;
use App\Actions\Payroll\CreatePayroll;
use App\Actions\Payroll\MarkPayrollPaid;
use App\Filament\App\Resources\Payrolls\PayrollResource;
use App\Models\Branch;
use App\Models\CommissionLog;
use App\Models\Customer;
use App\Models\EmployeeLoan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Payroll;
use App\Models\ProductionLog;
use App\Models\RolePermission;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_payroll_from_base_salary_commissions_and_loan_deduction(): void
    {
        [$tenant, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $this->addCommission($tenant, $employee, '250000.00');
        $this->addCommission($tenant, $employee, '125000.00');
        $loan = app(CreateEmployeeLoan::class)->handle($owner, (int) $employee->getKey(), '500000.00');

        $payroll = app(CreatePayroll::class)->handle(
            $owner,
            (int) $employee->getKey(),
            now()->format('Y-m'),
            '200000.00',
        );

        $this->assertSame($tenant->id, $payroll->tenant_id);
        $this->assertSame($employee->id, $payroll->user_id);
        $this->assertSame('5000000.00', $payroll->base_salary);
        $this->assertSame('375000.00', $payroll->commission_total);
        $this->assertSame('200000.00', $payroll->loan_deduction);
        $this->assertSame('5175000.00', $payroll->net_salary);
        $this->assertSame('draft', $payroll->status);
        $this->assertSame('500000.00', $loan->fresh()->remaining_amount);
        $this->assertSame(0, $loan->repayments()->count());
    }

    public function test_marking_payroll_paid_records_loan_repayments_once_and_preserves_the_payroll_snapshot(): void
    {
        [, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $loan = app(CreateEmployeeLoan::class)->handle($owner, (int) $employee->getKey(), '500000.00');
        $payroll = app(CreatePayroll::class)->handle(
            $owner,
            (int) $employee->getKey(),
            now()->format('Y-m'),
            '200000.00',
        );

        $paidPayroll = app(MarkPayrollPaid::class)->handle($owner, (int) $payroll->getKey());

        $this->assertSame('paid', $paidPayroll->status);
        $this->assertNotNull($paidPayroll->paid_at);
        $this->assertSame('4800000.00', $paidPayroll->net_salary);
        $this->assertSame('300000.00', $loan->fresh()->remaining_amount);
        $this->assertSame('active', $loan->fresh()->status);

        $this->assertDatabaseHas('employee_loan_repayments', [
            'employee_loan_id' => $loan->id,
            'payroll_id' => $payroll->id,
            'recorded_by' => $owner->id,
            'amount' => '200000.00',
            'notes' => 'Potongan kasbon payroll '.$payroll->period_month,
        ]);

        try {
            app(MarkPayrollPaid::class)->handle($owner, (int) $payroll->getKey());
            $this->fail('A paid payroll must not be paid twice.');
        } catch (DomainException) {
            $this->assertSame(1, $loan->repayments()->count());
            $this->assertSame('300000.00', $loan->fresh()->remaining_amount);
        }
    }

    public function test_payroll_rejects_duplicate_periods_future_periods_and_invalid_deductions(): void
    {
        [, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $loan = app(CreateEmployeeLoan::class)->handle($owner, (int) $employee->getKey(), '100000.00');

        app(CreatePayroll::class)->handle(
            $owner,
            (int) $employee->getKey(),
            now()->format('Y-m'),
            '0.00',
        );

        foreach ([
            [now()->format('Y-m'), '0.00'],
            [now()->addMonth()->format('Y-m'), '0.00'],
            [now()->format('Y-m'), '100000.01'],
            [now()->format('Y-m'), '-1.00'],
        ] as [$period, $deduction]) {
            try {
                app(CreatePayroll::class)->handle($owner, (int) $employee->getKey(), $period, $deduction);
                $this->fail('Invalid payroll input should be rejected.');
            } catch (DomainException) {
                $this->assertSame(1, Payroll::query()->count());
                $this->assertSame('100000.00', $loan->fresh()->remaining_amount);
            }
        }
    }

    public function test_payroll_deduction_cannot_exceed_net_salary(): void
    {
        [, $owner, $employee] = $this->context(baseSalary: '100000.00');
        $this->actingAs($owner);
        app(CreateEmployeeLoan::class)->handle($owner, (int) $employee->getKey(), '150000.00');

        try {
            app(CreatePayroll::class)->handle(
                $owner,
                (int) $employee->getKey(),
                now()->format('Y-m'),
                '150000.00',
            );
            $this->fail('Payroll must not create a negative net salary.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payrolls', 0);
        }
    }

    public function test_stale_payroll_deduction_is_rejected_without_partial_updates(): void
    {
        [, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $loan = app(CreateEmployeeLoan::class)->handle($owner, (int) $employee->getKey(), '500000.00');
        $payroll = app(CreatePayroll::class)->handle(
            $owner,
            (int) $employee->getKey(),
            now()->format('Y-m'),
            '300000.00',
        );

        app(RecordEmployeeLoanRepayment::class)->handle($owner, (int) $loan->getKey(), '250000.00');

        try {
            app(MarkPayrollPaid::class)->handle($owner, (int) $payroll->getKey());
            $this->fail('A payroll must not deduct more than the remaining loan balance.');
        } catch (DomainException) {
            $this->assertSame('draft', $payroll->fresh()->status);
            $this->assertSame('250000.00', $loan->fresh()->remaining_amount);
            $this->assertSame(1, $loan->repayments()->count());
            $this->assertDatabaseCount('employee_loan_repayments', 1);
        }
    }

    public function test_payroll_is_tenant_scoped_and_rejects_foreign_employees(): void
    {
        [, $owner, $employee] = $this->context();
        [, , $foreignEmployee] = $this->context();
        $this->actingAs($owner);

        try {
            app(CreatePayroll::class)->handle(
                $owner,
                (int) $foreignEmployee->getKey(),
                now()->format('Y-m'),
                '0.00',
            );
            $this->fail('A tenant cannot create payroll for another tenant employee.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payrolls', 0);
        }

        $payroll = app(CreatePayroll::class)->handle(
            $owner,
            (int) $employee->getKey(),
            now()->format('Y-m'),
            '0.00',
        );

        $this->assertSame([$payroll->id], PayrollResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_branch_manager_can_only_create_payroll_for_staff_fully_assigned_to_managed_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $managedBranch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();

        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($managedBranch);

        $assignedEmployee = User::factory()->for($tenant)->withRole('operator')->create([
            'monthly_base_salary' => '3500000.00',
        ]);
        $assignedEmployee->branches()->attach($managedBranch);

        $sharedEmployee = User::factory()->for($tenant)->withRole('cashier')->create();
        $sharedEmployee->branches()->attach([$managedBranch->id, $otherBranch->id]);

        $this->grantPermission($tenant, 'branch_manager', 'payroll.view');
        $this->grantPermission($tenant, 'branch_manager', 'payroll.manage');
        $this->actingAs($manager);

        $payroll = app(CreatePayroll::class)->handle(
            $manager,
            (int) $assignedEmployee->getKey(),
            now()->format('Y-m'),
            '0.00',
        );

        $this->assertSame($assignedEmployee->id, $payroll->user_id);

        try {
            app(CreatePayroll::class)->handle(
                $manager,
                (int) $sharedEmployee->getKey(),
                now()->format('Y-m'),
                '0.00',
            );
            $this->fail('A branch manager cannot process staff shared with another branch.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payrolls', 1);
        }
    }

    public function test_cashiers_cannot_manage_payroll_even_when_calling_the_action_directly(): void
    {
        [, , $employee] = $this->context();
        $this->actingAs($employee);

        try {
            app(CreatePayroll::class)->handle(
                $employee,
                (int) $employee->getKey(),
                now()->format('Y-m'),
                '0.00',
            );
            $this->fail('Cashiers cannot manage payroll.');
        } catch (DomainException) {
            $this->assertDatabaseCount('payrolls', 0);
        }
    }

    public function test_payroll_action_ignores_forged_owner_role_on_authenticated_user_model(): void
    {
        [$tenant, , $employee] = $this->context();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $this->actingAs($cashier);

        $forgedActor = new User();
        $forgedActor->setRawAttributes([
            'id' => $cashier->getKey(),
            'tenant_id' => $tenant->getKey(),
            'role' => 'tenant_owner',
            'is_active' => true,
        ], true);

        try {
            app(CreatePayroll::class)->handle(
                $forgedActor,
                (int) $employee->getKey(),
                now()->format('Y-m'),
                '0.00',
            );
            $this->fail('The action must authorize the persisted cashier role, not forged model attributes.');
        } catch (DomainException $exception) {
            $this->assertSame('Anda tidak memiliki izin untuk mengelola payroll.', $exception->getMessage());
        }

        $this->assertDatabaseCount('payrolls', 0);
    }

    public function test_read_only_tenant_cannot_create_payroll(): void
    {
        [$tenant, $owner, $employee] = $this->context();
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();
        $this->actingAs($owner);

        try {
            app(CreatePayroll::class)->handle($owner, (int) $employee->getKey(), now()->format('Y-m'), '0.00');
            $this->fail('A read-only tenant must not create payroll.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('payrolls', 0);
        }
    }

    public function test_read_only_tenant_cannot_mark_payroll_paid_or_repay_employee_loan(): void
    {
        [$tenant, $owner, $employee] = $this->context();
        $this->actingAs($owner);

        $loan = app(CreateEmployeeLoan::class)->handle($owner, (int) $employee->getKey(), '500000.00');
        $payroll = app(CreatePayroll::class)->handle($owner, (int) $employee->getKey(), now()->format('Y-m'), '200000.00');
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        try {
            app(MarkPayrollPaid::class)->handle($owner, (int) $payroll->getKey());
            $this->fail('A read-only tenant must not mark payroll as paid.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $payroll->fresh()->status);
            $this->assertSame('500000.00', $loan->fresh()->remaining_amount);
            $this->assertDatabaseCount('employee_loan_repayments', 0);
        }
    }

    private function context(string $baseSalary = '5000000.00'): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $employee = User::factory()->for($tenant)->withRole('operator')->create([
            'monthly_base_salary' => $baseSalary,
        ]);
        $employee->branches()->attach($branch);

        return [$tenant, $owner, $employee];
    }

    private function addCommission(Tenant $tenant, User $employee, string $amount): void
    {
        $branch = $employee->branches()->firstOrFail();
        $customer = Customer::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create();
        $order = Order::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'service_id' => $service->getKey(),
        ]);
        $productionLog = ProductionLog::factory()
            ->forOrder($order)
            ->forWorker($employee)
            ->create([
                'finished_at' => now()->startOfMonth()->addDays(4),
            ]);

        CommissionLog::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $employee->getKey(),
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            'production_log_id' => $productionLog->getKey(),
            'service_id' => $service->getKey(),
            'amount' => $amount,
            'earned_at' => now()->startOfMonth()->addDays(4),
        ]);
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Karyawan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->getKey(),
            'role' => $role,
            'permission_id' => $permission->getKey(),
        ]);
    }
}
