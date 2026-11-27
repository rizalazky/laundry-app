<?php

namespace Tests\Feature;

use App\Actions\Accounting\RecordBankTransfer;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\RolePermission;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankTransferAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_without_finance_permission_cannot_record_bank_transfer(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        [$source, $destination] = $this->bankAccounts($tenant);

        $this->actingAs($manager);

        $this->expectException(DomainException::class);

        try {
            $this->recordTransfer($tenant, $source, $destination, $branch, 'BANK-AUTH-001');
        } finally {
            $this->assertDatabaseCount('bank_transfers', 0);
            $this->assertDatabaseCount('journals', 0);
        }
    }

    public function test_branch_manager_cannot_record_transfer_for_an_unassigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $unassignedBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);
        $this->grantFinanceManage($tenant);
        [$source, $destination] = $this->bankAccounts($tenant);

        $this->actingAs($manager);

        try {
            $this->recordTransfer($tenant, $source, $destination, $unassignedBranch, 'BANK-AUTH-002');
            $this->fail('A branch manager must not record transfers for an unassigned branch.');
        } catch (DomainException $exception) {
            $this->assertSame('Anda tidak memiliki akses ke cabang ini.', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_transfers', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_branch_manager_can_record_transfer_for_an_assigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        $this->grantFinanceManage($tenant);
        [$source, $destination] = $this->bankAccounts($tenant);

        $this->actingAs($manager);

        $transfer = $this->recordTransfer($tenant, $source, $destination, $branch, 'BANK-AUTH-003');

        $this->assertDatabaseHas('bank_transfers', [
            'id' => $transfer->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('journals', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'reference_type' => 'bank_transfer',
            'reference_id' => $transfer->id,
            'created_by' => $manager->id,
        ]);
    }

    public function test_branch_manager_cannot_record_a_branchless_bank_transfer(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        $this->grantFinanceManage($tenant);
        [$source, $destination] = $this->bankAccounts($tenant);

        $this->actingAs($manager);

        $this->expectException(DomainException::class);

        try {
            $this->recordTransfer($tenant, $source, $destination, null, 'BANK-AUTH-004');
        } finally {
            $this->assertDatabaseCount('bank_transfers', 0);
        }
    }

    public function test_tenant_owner_can_record_a_branchless_bank_transfer(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        [$source, $destination] = $this->bankAccounts($tenant);

        $this->actingAs($owner);

        $transfer = $this->recordTransfer($tenant, $source, $destination, null, 'BANK-AUTH-005');

        $this->assertDatabaseHas('bank_transfers', [
            'id' => $transfer->id,
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'created_by' => $owner->id,
        ]);
    }

    public function test_authenticated_actor_cannot_forge_another_users_created_by_id(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignUser = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        [$source, $destination] = $this->bankAccounts($tenant);

        $this->actingAs($owner);

        try {
            $this->recordTransfer($tenant, $source, $destination, null, 'BANK-AUTH-006', $foreignUser->id);
            $this->fail('An authenticated actor must not forge the transfer creator.');
        } catch (DomainException $exception) {
            $this->assertSame('Pengguna pencatat transfer harus sesuai dengan akun yang sedang login.', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_transfers', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_trusted_internal_call_cannot_assign_a_foreign_tenant_user_as_creator(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignUser = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        [$source, $destination] = $this->bankAccounts($tenant);

        try {
            $this->recordTransfer($tenant, $source, $destination, null, 'BANK-AUTH-007', $foreignUser->id);
            $this->fail('A transfer must not reference a creator from another tenant.');
        } catch (DomainException $exception) {
            $this->assertSame('Pengguna pencatat transfer tidak ditemukan pada tenant ini.', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_transfers', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_authenticated_user_cannot_record_transfer_for_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        [$source, $destination] = $this->bankAccounts($foreignTenant);

        $this->actingAs($owner);

        $this->expectException(DomainException::class);

        try {
            $this->recordTransfer($foreignTenant, $source, $destination, null, 'BANK-AUTH-008');
        } finally {
            $this->assertDatabaseCount('bank_transfers', 0);
            $this->assertDatabaseCount('journals', 0);
        }
    }

    /**
     * @return array{BankAccount, BankAccount}
     */
    private function bankAccounts(Tenant $tenant): array
    {
        $sourceCoa = ChartOfAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'code' => '9101',
            'name' => 'Bank Sumber',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);
        $destinationCoa = ChartOfAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'code' => '9102',
            'name' => 'Bank Tujuan',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $source = BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $sourceCoa->id,
            'bank_name' => 'Bank Sumber',
            'account_number' => 'SRC-'.$tenant->id,
            'holder_name' => 'Laundry Owner',
            'is_active' => true,
        ]);
        $destination = BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $destinationCoa->id,
            'bank_name' => 'Bank Tujuan',
            'account_number' => 'DST-'.$tenant->id,
            'holder_name' => 'Laundry Owner',
            'is_active' => true,
        ]);

        return [$source, $destination];
    }

    private function grantFinanceManage(Tenant $tenant): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'finance.manage'],
            ['name' => 'Kelola Keuangan', 'group' => 'Keuangan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $permission->id,
        ]);
    }

    private function recordTransfer(
        Tenant $tenant,
        BankAccount $source,
        BankAccount $destination,
        ?Branch $branch,
        string $reference,
        ?int $createdBy = null,
    ): BankTransfer {
        return app(RecordBankTransfer::class)->handle(
            tenantId: $tenant->id,
            sourceBankId: $source->id,
            destinationBankId: $destination->id,
            amount: '125000.00',
            transferredAt: now(),
            branchId: $branch?->id,
            referenceNumber: $reference,
            createdBy: $createdBy,
        );
    }

    public function test_tenant_owner_cannot_record_branchless_transfer_after_subscription_expiry(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        [$source, $destination] = $this->bankAccounts($tenant);
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $this->actingAs($owner);

        try {
            $this->recordTransfer($tenant, $source, $destination, null, 'BANK-READONLY-001');
            $this->fail('A read-only tenant must not record a bank transfer.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('read-only', $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_transfers', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_tenant_owner_can_record_branchless_transfer_during_subscription_grace_period(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        [$source, $destination] = $this->bankAccounts($tenant);
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $plan = Plan::query()->create([
            'code' => 'bank-grace-' . fake()->unique()->lexify('??????'),
            'name' => 'Grace Test Plan',
            'monthly_price' => 99000,
            'is_active' => true,
        ]);

        Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
            'grace_ends_at' => now()->addDay(),
        ]);

        $this->actingAs($owner);

        $transfer = $this->recordTransfer($tenant, $source, $destination, null, 'BANK-GRACE-001');

        $this->assertDatabaseHas('bank_transfers', [
            'id' => $transfer->id,
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'created_by' => $owner->id,
        ]);
    }

}
