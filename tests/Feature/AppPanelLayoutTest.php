<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Tenancy\EditBranchProfile;
use App\Filament\App\Pages\Tenancy\RegisterBranch;
use App\Models\Branch;
use Filament\Facades\Filament;
use Tests\TestCase;

class AppPanelLayoutTest extends TestCase
{
    public function test_owner_panel_exposes_the_dashboard_shell_and_branch_switcher_configuration(): void
    {
        $panel = Filament::getPanel('app');

        $this->assertSame('Laundry App', $panel->getBrandName());
        $this->assertSame('17rem', $panel->getSidebarWidth());

        $this->assertSame([
            'Operasional',
            'Pelanggan & CRM',
            'Karyawan & SDM',
            'Inventori & Distribusi',
            'Keuangan & Laporan',
            'Pengaturan',
        ], array_values($panel->getNavigationGroups()));

        $this->assertSame(Branch::class, $panel->getTenantModel());
        $this->assertSame('code', $panel->getTenantSlugAttribute());
        $this->assertTrue($panel->hasTenantMenu());
        $this->assertTrue($panel->hasTenantSwitcher());
        $this->assertTrue($panel->isTenantMenuSearchable());
        $this->assertTrue($panel->hasTenantProfile());
        $this->assertTrue($panel->hasTenantRegistration());
        $this->assertSame(EditBranchProfile::class, $panel->getTenantProfilePage());
        $this->assertSame(RegisterBranch::class, $panel->getTenantRegistrationPage());
    }
}
