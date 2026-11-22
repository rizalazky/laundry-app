<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Filament\App\Pages\ManualJournal;
use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use App\Filament\App\Resources\CashSettlements\CashSettlementResource;
use App\Filament\App\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\App\Resources\EdcMachines\EdcMachineResource;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_access_finance_management_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $this->assertTrue(ChartOfAccountResource::canAccess());
        $this->assertTrue(BankAccountResource::canAccess());
        $this->assertTrue(EdcMachineResource::canAccess());
        $this->assertTrue(CashSettlementResource::canAccess());
        $this->assertTrue(ManualJournal::canAccess());
    }

    public function test_staff_without_finance_permission_cannot_access_finance_management_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $this->assertFalse(ChartOfAccountResource::canAccess());
        $this->assertFalse(BankAccountResource::canAccess());
        $this->assertFalse(EdcMachineResource::canAccess());
        $this->assertFalse(CashSettlementResource::canAccess());
        $this->assertFalse(ManualJournal::canAccess());
    }

    public function test_finance_permission_can_be_granted_to_a_branch_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        foreach (['finance.view', 'finance.manage'] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Keuangan'],
            );

            RolePermission::query()->create([
                'tenant_id' => $tenant->id,
                'role' => 'branch_manager',
                'permission_id' => $permission->id,
            ]);
        }

        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $this->assertTrue(ChartOfAccountResource::canAccess());
        $this->assertTrue(ManualJournal::canAccess());
    }

    public function test_manual_journal_page_creates_balanced_journal_for_the_active_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(ManualJournal::class)
            ->set('journalDate', '2026-11-22T09:00')
            ->set('branchId', (string) $branch->id)
            ->set('description', 'Setoran modal awal')
            ->set('entries', [
                ['account_code' => '1000', 'debit' => '500000.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '500000.00'],
            ])
            ->call('createJournal')
            ->assertHasNoErrors();

        $journal = Journal::query()->where('tenant_id', $tenant->id)->sole();

        $this->assertSame('Setoran modal awal', $journal->description);
        $this->assertCount(2, $journal->entries);
        $this->assertSame('500000.00', $journal->entries->firstWhere('debit', '500000.00')->debit);
        $this->assertSame('500000.00', $journal->entries->firstWhere('credit', '500000.00')->credit);
    }

    public function test_manual_journal_rejects_unbalanced_entries_without_writing_a_journal(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(ManualJournal::class)
            ->set('journalDate', '2026-11-22T09:00')
            ->set('branchId', (string) $branch->id)
            ->set('description', 'Jurnal tidak seimbang')
            ->set('entries', [
                ['account_code' => '1000', 'debit' => '500000.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '400000.00'],
            ])
            ->call('createJournal')
            ->assertHasErrors('entries');

        $this->assertSame(0, Journal::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_manual_journal_cannot_use_an_account_code_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        ChartOfAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $foreignTenant->id,
            'code' => '9999',
            'name' => 'Akun Rahasia Tenant Lain',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(ManualJournal::class)
            ->set('journalDate', '2026-11-22T09:00')
            ->set('branchId', (string) $branch->id)
            ->set('description', 'Percobaan lintas tenant')
            ->set('entries', [
                ['account_code' => '9999', 'debit' => '100000.00', 'credit' => '0.00'],
                ['account_code' => '1000', 'debit' => '0.00', 'credit' => '100000.00'],
            ])
            ->call('createJournal')
            ->assertHasErrors('entries');

        $this->assertSame(0, Journal::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_system_chart_of_accounts_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $systemAccount = ChartOfAccount::query()->where('code', '1000')->firstOrFail();

        $this->actingAs($owner);

        $this->assertFalse(ChartOfAccountResource::canEdit($systemAccount));
    }
}
