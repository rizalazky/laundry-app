<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BusinessPerformance;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessPerformancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_business_performance_page_and_see_analytics_sections(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        Livewire::test(BusinessPerformance::class)
            ->assertSet('branchId', 'all')
            ->assertSee('Ringkasan performa operasional')
            ->assertSee('Total omzet pesanan')
            ->assertSee('Performa outlet')
            ->assertSee('Performa layanan')
            ->assertSee('Tren omzet')
            ->assertSee('Dampak promosi')
            ->assertSee('Total pengeluaran')
            ->assertSee('Diskon promosi')
            ->assertSee('Produktivitas karyawan')
            ->assertSee('Komisi diperoleh');
    }

    public function test_page_reports_an_invalid_date_range_without_running_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        Livewire::test(BusinessPerformance::class)
            ->set('fromDate', '2026-11-30')
            ->set('toDate', '2026-11-01')
            ->assertSee('Tanggal awal tidak boleh melewati tanggal akhir.');
    }

    public function test_users_without_report_permission_cannot_access_business_performance_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('operator')->create();

        $this->assertFalse(BusinessPerformance::canAccess());
        $this->actingAs($user);
        $this->assertFalse(BusinessPerformance::canAccess());
    }

    public function test_business_analytics_are_denied_after_reports_permission_is_revoked(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'reports.view'],
            ['name' => 'Lihat Laporan', 'group' => 'Laporan'],
        );

        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $component = Livewire::test(BusinessPerformance::class);

        RolePermission::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'branch_manager')
            ->where('permission_id', $permission->id)
            ->delete();

        $component->call('getAnalytics')->assertForbidden();
    }
}
