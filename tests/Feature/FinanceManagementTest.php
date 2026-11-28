<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Filament\App\Pages\ManualJournal;
use App\Filament\App\Resources\CashSettlements\Pages\CreateCashSettlement;
use App\Models\BankAccount;
use App\Models\CashSettlement;
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


    public function test_manual_journal_cannot_claim_system_owned_source_references(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        foreach (CreateJournal::SYSTEM_REFERENCE_TYPES as $referenceType) {
            Livewire::test(ManualJournal::class)
                ->set('journalDate', '2026-11-22T09:00')
                ->set('branchId', (string) $branch->id)
                ->set('description', 'Manual journal reference collision probe')
                ->set('referenceType', strtoupper($referenceType))
                ->set('referenceId', '999')
                ->set('entries', [
                    ['account_code' => '1000', 'debit' => '100000.00', 'credit' => '0.00'],
                    ['account_code' => '3000', 'debit' => '0.00', 'credit' => '100000.00'],
                ])
                ->call('createJournal')
                ->assertHasErrors('referenceType');
        }

        $this->assertSame(0, Journal::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_cash_settlement_create_page_posts_the_settlement_and_balanced_journal(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['is_workshop' => false]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $bankCoa = ChartOfAccount::query()->where('code', '1010')->firstOrFail();
        $bank = BankAccount::query()->create([
            'tenant_id' => $tenant->id,
            'coa_id' => $bankCoa->id,
            'bank_name' => 'Bank Test',
            'account_number' => '1234567890',
            'holder_name' => 'Laundry Test',
            'is_active' => true,
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateCashSettlement::class)
            ->fillForm([
                'source_type' => CashSettlement::SOURCE_OUTLET_CASH,
                'branch_id' => (string) $branch->id,
                'target_bank_id' => (string) $bank->id,
                'gross_amount' => '250000.00',
                'reference_number' => 'UI-CASH-SETTLE-001',
                'settled_at' => '2026-11-22 09:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $settlement = CashSettlement::query()->where('tenant_id', $tenant->id)->sole();

        $this->assertSame('250000.00', $settlement->gross_amount);
        $this->assertSame('250000.00', $settlement->net_amount);
        $this->assertDatabaseHas('journals', [
            'tenant_id' => $tenant->id,
            'reference_type' => 'cash_settlement',
            'reference_id' => $settlement->id,
        ]);
    }

    public function test_system_chart_of_accounts_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $systemAccount = ChartOfAccount::query()->where('code', '1000')->firstOrFail();

        $this->actingAs($owner);

        $this->assertFalse(ChartOfAccountResource::canEdit($systemAccount));
    }

    public function test_manual_journal_rechecks_finance_permission_when_livewire_mutation_runs(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'finance.manage'],
            ['name' => 'Kelola Keuangan', 'group' => 'Keuangan'],
        );

        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $component = Livewire::test(ManualJournal::class)
            ->set('journalDate', '2026-11-25T09:00')
            ->set('branchId', (string) $branch->id)
            ->set('description', 'Jurnal setelah izin dicabut')
            ->set('entries', [
                ['account_code' => '1000', 'debit' => '100000.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '100000.00'],
            ]);

        RolePermission::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'branch_manager')
            ->where('permission_id', $permission->id)
            ->delete();

        $component->call('createJournal')->assertForbidden();

        $this->assertSame(0, Journal::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_manual_journal_rechecks_page_access_on_every_livewire_request(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $component = Livewire::test(ManualJournal::class);

        $owner->update(['is_active' => false]);

        $component->call('getAccountOptions')->assertForbidden();
    }
}
