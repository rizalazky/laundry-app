<?php

namespace Tests\Feature;

use App\Actions\Accounting\RecordExpense;
use App\Actions\Accounting\RecordFinancialSettlement;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceActionBranchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_cannot_record_expense_for_an_unassigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $unassignedBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);
        $this->grantFinanceManage($tenant, $manager);

        $expenseAccount = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('code', '5400')
            ->firstOrFail();

        $this->actingAs($manager);

        try {
            app(RecordExpense::class)->handle(
                tenantId: $tenant->id,
                branchId: $unassignedBranch->id,
                expenseAccountId: $expenseAccount->id,
                paymentSourceType: 'cash',
                bankAccountId: null,
                amount: '25000',
                description: 'Forged branch expense',
                spentAt: now(),
            );

            $this->fail('A branch manager must not record expenses for an unassigned branch.');
        } catch (DomainException $exception) {
            $this->assertSame('Anda tidak memiliki akses ke cabang ini.', $exception->getMessage());
        }

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_branch_manager_cannot_record_settlement_for_an_unassigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $unassignedBranch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);
        $this->grantFinanceManage($tenant, $manager);
        $bank = $this->bankAccount($tenant);

        $this->actingAs($manager);

        try {
            app(RecordFinancialSettlement::class)->handle(
                tenantId: $tenant->id,
                branchId: $unassignedBranch->id,
                sourceType: CashSettlement::SOURCE_OUTLET_CASH,
                targetBankId: $bank->id,
                grossAmount: '100000',
                mdrAmount: '0',
                netAmount: '100000',
                settledAt: now(),
                referenceNumber: 'FORGED-BRANCH-SETTLEMENT-001',
            );

            $this->fail('A branch manager must not record settlements for an unassigned branch.');
        } catch (DomainException $exception) {
            $this->assertSame('Anda tidak memiliki akses ke cabang ini.', $exception->getMessage());
        }

        $this->assertDatabaseCount('cash_settlements', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    private function grantFinanceManage(Tenant $tenant, User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'finance.manage'],
            ['name' => 'Kelola Keuangan', 'group' => 'Keuangan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $user->role,
            'permission_id' => $permission->id,
        ]);
    }

    private function bankAccount(Tenant $tenant): BankAccount
    {
        $account = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('code', '1010')
            ->firstOrFail();

        return BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $account->id,
            'bank_name' => 'Bank Test',
            'account_number' => '1234567890',
            'holder_name' => 'Laundry Test',
            'is_active' => true,
        ]);
    }
}
