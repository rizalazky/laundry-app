<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Dashboard;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_requires_dashboard_view_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();

        $this->actingAs($cashier);

        $this->assertFalse(Dashboard::canAccess());

        $this->grantPermission($tenant, 'cashier', 'dashboard.view');

        $this->assertTrue(Dashboard::canAccess());
    }

    public function test_inactive_staff_cannot_access_dashboard_even_with_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create([
            'is_active' => false,
        ]);

        $this->grantPermission($tenant, 'cashier', 'dashboard.view');
        $this->actingAs($cashier);

        $this->assertFalse(Dashboard::canAccess());
    }

    public function test_tenant_owner_keeps_dashboard_access_without_matrix_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        $this->assertTrue(Dashboard::canAccess());
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Lihat Dashboard', 'group' => 'Dashboard'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
