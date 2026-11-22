<?php

namespace Tests\\Feature;

use App\\Filament\\App\\Pages\\BusinessPerformance;
use App\\Models\\Tenant;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Livewire\\Livewire;
use Tests\\TestCase;

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
            ->assertSee('Produktivitas karyawan');
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
}
