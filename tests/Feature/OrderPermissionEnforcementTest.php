<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->for($this->tenant)->create(['code' => 'PUSAT']);
    }

    private function staffWithPermissions(array $slugs): User
    {
        $user = User::factory()->for($this->tenant)->withRole('cashier')->create();
        $user->branches()->attach($this->branch);

        foreach ($slugs as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Pesanan'],
            );

            RolePermission::query()->create([
                'tenant_id' => $this->tenant->id,
                'role' => 'cashier',
                'permission_id' => $permission->id,
            ]);
        }

        return $user;
    }

    public function test_cashier_without_order_view_permission_cannot_open_order_list(): void
    {
        $user = $this->staffWithPermissions([]);

        $this->actingAs($user)
            ->get('/app/PUSAT/orders')
            ->assertForbidden();
    }

    public function test_cashier_with_order_view_permission_can_open_order_list(): void
    {
        $user = $this->staffWithPermissions(['orders.view']);

        $this->actingAs($user)
            ->get('/app/PUSAT/orders')
            ->assertOk();
    }

    public function test_cashier_without_order_create_permission_cannot_open_create_page(): void
    {
        $user = $this->staffWithPermissions(['orders.view']);

        $this->actingAs($user)
            ->get('/app/PUSAT/orders/create')
            ->assertForbidden();
    }

    public function test_cashier_with_order_create_permission_can_open_create_page(): void
    {
        $user = $this->staffWithPermissions(['orders.view', 'orders.create']);

        $this->actingAs($user)
            ->get('/app/PUSAT/orders/create')
            ->assertOk();
    }

    public function test_tenant_owner_retains_full_order_access_without_matrix_assignments(): void
    {
        $owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner)
            ->get('/app/PUSAT/orders')
            ->assertOk();

        $this->actingAs($owner)
            ->get('/app/PUSAT/orders/create')
            ->assertOk();
    }
}
