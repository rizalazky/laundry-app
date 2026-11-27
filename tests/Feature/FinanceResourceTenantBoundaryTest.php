<?php

namespace Tests\Feature;

use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use App\Filament\App\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\App\Resources\EdcMachines\EdcMachineResource;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\EdcMachine;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceResourceTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_master_resources_do_not_expose_records_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();

        $branch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $localAccount = $this->createAccount($tenant, 'LOCAL-9100');
        $foreignAccount = $this->createAccount($foreignTenant, 'FOREIGN-9100');

        $localBank = $this->createBankAccount($tenant, $localAccount, 'LOCAL-ACCOUNT');
        $foreignBank = $this->createBankAccount($foreignTenant, $foreignAccount, 'FOREIGN-ACCOUNT');

        $localMachine = $this->createEdcMachine($tenant, $branch, $localBank, 'Local EDC');
        $foreignMachine = $this->createEdcMachine($foreignTenant, $foreignBranch, $foreignBank, 'Foreign EDC');

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $accountIds = ChartOfAccountResource::getEloquentQuery()->pluck('id')->all();
        $bankIds = BankAccountResource::getEloquentQuery()->pluck('id')->all();
        $machineIds = EdcMachineResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($localAccount->id, $accountIds);
        $this->assertNotContains($foreignAccount->id, $accountIds);
        $this->assertTrue(ChartOfAccountResource::canEdit($localAccount));
        $this->assertFalse(ChartOfAccountResource::canEdit($foreignAccount));

        $this->assertContains($localBank->id, $bankIds);
        $this->assertNotContains($foreignBank->id, $bankIds);

        $this->assertContains($localMachine->id, $machineIds);
        $this->assertNotContains($foreignMachine->id, $machineIds);
    }

    private function createAccount(Tenant $tenant, string $code): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name' => $code.' Test Account',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function createBankAccount(Tenant $tenant, ChartOfAccount $account, string $number): BankAccount
    {
        return BankAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $account->id,
            'bank_name' => 'Bank '.$number,
            'account_number' => $number,
            'holder_name' => 'Laundry Test',
            'is_active' => true,
        ]);
    }

    private function createEdcMachine(
        Tenant $tenant,
        Branch $branch,
        BankAccount $bankAccount,
        string $name,
    ): EdcMachine {
        return EdcMachine::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'bank_account_id' => $bankAccount->id,
            'terminal_name' => $name,
            'mdr_percent' => '1.50',
            'is_active' => true,
        ]);
    }
}
