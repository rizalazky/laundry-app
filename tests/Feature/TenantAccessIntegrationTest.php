<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAccessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_a_tenant_panel_route(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'GUEST']);

        $this->get('/app/GUEST/orders')
            ->assertRedirect(route('filament.app.auth.login'));
    }

    public function test_staff_cannot_switch_to_an_unassigned_branch_even_within_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create(['code' => 'ASSIGNED']);
        Branch::factory()->for($tenant)->create(['code' => 'UNASSIGNED']);
        $cashier = $this->cashierFor($tenant, $assignedBranch);

        $this->actingAs($cashier)
            ->get('/app/ASSIGNED/orders')
            ->assertOk();

        $this->actingAs($cashier)
            ->get('/app/UNASSIGNED/orders')
            ->assertNotFound();
    }

    public function test_staff_cannot_switch_to_a_branch_owned_by_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'LOCAL']);
        Branch::factory()->for($foreignTenant)->create(['code' => 'FOREIGN']);
        $cashier = $this->cashierFor($tenant, $branch);

        $this->actingAs($cashier)
            ->get('/app/FOREIGN/orders')
            ->assertNotFound();
    }

    public function test_inactive_staff_cannot_reenter_the_tenant_panel(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'INACTIVE']);
        $cashier = $this->cashierFor($tenant, $branch);
        $cashier->update(['is_active' => false]);

        $this->actingAs($cashier)
            ->get('/app/INACTIVE/orders')
            ->assertForbidden();
    }

    private function cashierFor(Tenant $tenant, Branch $branch): User
    {
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $cashier->branches()->attach($branch);

        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'orders.view'],
            ['name' => 'View orders', 'group' => 'Orders'],
        );

        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);

        return $cashier;
    }
}
