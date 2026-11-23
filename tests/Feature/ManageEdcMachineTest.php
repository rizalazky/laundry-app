<?php

namespace Tests\Feature;

use App\Actions\Accounting\ManageEdcMachine;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\EdcMachine;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManageEdcMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_cannot_create_edc_machine_for_an_unassigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $assigned = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $unassigned = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assigned);
        $this->grantFinanceManage($tenant);
        $bank = $this->bankAccount($tenant);

        $this->actingAs($manager);

        try {
            app(ManageEdcMachine::class)->create($manager, $this->payload($unassigned, $bank));
            $this->fail('A branch manager must not create an EDC machine for an unassigned branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.branch_id', $exception->errors());
        }

        $this->assertDatabaseCount('edc_machines', 0);
    }

    public function test_branch_manager_can_create_edc_machine_for_an_assigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        $this->grantFinanceManage($tenant);
        $bank = $this->bankAccount($tenant);

        $this->actingAs($manager);

        $machine = app(ManageEdcMachine::class)->create($manager, $this->payload($branch, $bank));

        $this->assertSame($tenant->id, $machine->tenant_id);
        $this->assertSame($branch->id, $machine->branch_id);
        $this->assertSame($bank->id, $machine->bank_account_id);
    }

    public function test_branch_manager_cannot_move_edc_machine_to_an_unassigned_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $assigned = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $unassigned = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assigned);
        $this->grantFinanceManage($tenant);
        $bank = $this->bankAccount($tenant);
        $machine = EdcMachine::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $assigned->id,
            'bank_account_id' => $bank->id,
            'terminal_name' => 'EDC Existing',
            'mdr_percent' => '1.50',
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        try {
            app(ManageEdcMachine::class)->update(
                $manager,
                $machine,
                $this->payload($unassigned, $bank),
            );
            $this->fail('A branch manager must not move an EDC machine to an unassigned branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.branch_id', $exception->errors());
        }

        $this->assertDatabaseHas('edc_machines', [
            'id' => $machine->id,
            'branch_id' => $assigned->id,
        ]);
    }

    public function test_edc_machine_cannot_reference_a_bank_account_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $bank = $this->bankAccount($tenant);
        $foreignBank = $this->bankAccount($foreignTenant);

        $this->actingAs($owner);

        try {
            app(ManageEdcMachine::class)->create($owner, $this->payload($branch, $foreignBank));
            $this->fail('An EDC machine must not reference a bank account from another tenant.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.bank_account_id', $exception->errors());
        }

        $this->assertDatabaseCount('edc_machines', 0);
    }

    public function test_edc_machine_cannot_be_created_on_a_workshop_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $workshop = Branch::factory()->for($tenant)->create(['is_workshop' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $bank = $this->bankAccount($tenant);

        $this->actingAs($owner);

        try {
            app(ManageEdcMachine::class)->create($owner, $this->payload($workshop, $bank));
            $this->fail('An EDC machine must not be assigned to a workshop branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.branch_id', $exception->errors());
        }

        $this->assertDatabaseCount('edc_machines', 0);
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

    private function bankAccount(Tenant $tenant): BankAccount
    {
        $coa = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('code', '1010')
            ->firstOrFail();

        return BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $coa->id,
            'bank_name' => 'Bank Test',
            'account_number' => '1234567890-'.$tenant->id,
            'holder_name' => 'Laundry Test',
            'is_active' => true,
        ]);
    }

    private function payload(Branch $branch, BankAccount $bank): array
    {
        return [
            'branch_id' => $branch->id,
            'bank_account_id' => $bank->id,
            'terminal_name' => 'EDC Test',
            'mdr_percent' => '1.50',
            'is_active' => true,
        ];
    }
}
