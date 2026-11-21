<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Filament\App\Pages\AccountingReports;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountingReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_view_financial_report_tabs_and_income_statement_data(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->postJournal($tenant->id, $branch->id, '2026-01-10 09:00:00', 'Pendapatan laundry', [
            ['account_code' => '1000', 'debit' => '200000', 'credit' => '0'],
            ['account_code' => '4000', 'debit' => '0', 'credit' => '200000'],
        ]);
        $this->postJournal($tenant->id, $branch->id, '2026-01-11 09:00:00', 'Biaya operasional', [
            ['account_code' => '5400', 'debit' => '50000', 'credit' => '0'],
            ['account_code' => '1000', 'debit' => '0', 'credit' => '50000'],
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(AccountingReports::class)
            ->assertSee('Jurnal Umum')
            ->assertSee('Buku Besar')
            ->assertSee('Laba Rugi')
            ->assertSee('Neraca')
            ->set('fromDate', '2026-01-01')
            ->set('toDate', '2026-01-31')
            ->set('reportType', 'income-statement')
            ->assertSee('4000')
            ->assertSee('200.000,00')
            ->assertSee('50.000,00')
            ->assertSee('150.000,00');
    }

    public function test_non_owner_cannot_expand_report_scope_beyond_current_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        $reportsPermission = Permission::query()->firstOrCreate(
            ['slug' => 'reports.view'],
            ['name' => 'Lihat Laporan', 'group' => 'Laporan'],
        );
        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $reportsPermission->id,
        ]);

        $this->postJournal($tenant->id, $branch->id, '2026-01-10 09:00:00', 'Jurnal cabang sendiri', [
            ['account_code' => '1000', 'debit' => '100000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '100000'],
        ]);
        $this->postJournal($tenant->id, $otherBranch->id, '2026-01-10 10:00:00', 'Jurnal cabang lain', [
            ['account_code' => '1000', 'debit' => '200000', 'credit' => '0'],
            ['account_code' => '3000', 'debit' => '0', 'credit' => '200000'],
        ]);

        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(AccountingReports::class)
            ->set('fromDate', '2026-01-01')
            ->set('toDate', '2026-01-31')
            ->set('branchId', 'all')
            ->assertSee('Jurnal cabang sendiri')
            ->assertDontSee('Jurnal cabang lain');
    }

    public function test_page_is_not_available_to_inactive_or_unassigned_users(): void
    {
        $this->assertFalse(AccountingReports::canAccess());

        $tenant = Tenant::factory()->create();
        $inactiveOwner = User::factory()->for($tenant)->withRole('tenant_owner')->create([
            'is_active' => false,
        ]);

        $this->actingAs($inactiveOwner);

        $this->assertFalse(AccountingReports::canAccess());
    }

    public static function configurableStaffRoles(): array
    {
        return [
            'cashier' => ['cashier'],
            'operator' => ['operator'],
            'branch manager' => ['branch_manager'],
        ];
    }

    #[DataProvider('configurableStaffRoles')]
    public function test_staff_without_reports_view_permission_cannot_access_accounting_reports(string $role): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole($role)->create();

        $this->actingAs($user);

        $this->assertFalse(AccountingReports::canAccess());
    }

    #[DataProvider('configurableStaffRoles')]
    public function test_staff_with_reports_view_permission_can_access_accounting_reports(string $role): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole($role)->create();
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'reports.view'],
            ['name' => 'Lihat Laporan', 'group' => 'Laporan'],
        );

        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($user);

        $this->assertTrue(AccountingReports::canAccess());
    }

    public function test_tenant_owner_retains_accounting_reports_access_without_matrix_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        $this->assertTrue(AccountingReports::canAccess());
    }

    /**
     * @param  list<array{account_code: string, debit: string, credit: string}>  $entries
     */
    private function postJournal(int $tenantId, ?int $branchId, string $date, string $description, array $entries): void
    {
        app(CreateJournal::class)->handle(
            tenantId: $tenantId,
            branchId: $branchId,
            journalDate: CarbonImmutable::parse($date),
            referenceType: null,
            referenceId: null,
            description: $description,
            entries: $entries,
        );
    }
}
