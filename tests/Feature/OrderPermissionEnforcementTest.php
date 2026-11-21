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

            RolePermission::query()->firstOrCreate([
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
    private function grantPermission(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Pesanan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $this->tenant->id,
            'role' => $user->role,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_order_detail_and_status_payment_actions_require_their_own_permissions(): void
    {
        $user = $this->staffWithPermissions(['orders.view']);
        $order = Order::factory()->for($this->branch)->create();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('view', $order));
        $this->assertFalse($gate->allows('changeStatus', $order));
        $this->assertFalse($gate->allows('recordPayment', $order));

        $this->grantPermission($user, 'orders.update');
        $this->grantPermission($user, 'orders.payment');

        $this->assertTrue($gate->allows('changeStatus', $order));
        $this->assertTrue($gate->allows('recordPayment', $order));
    }

    public function test_refund_and_cancellation_require_manager_role_and_explicit_permissions(): void
    {
        $manager = User::factory()->for($this->tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($this->branch);
        $order = Order::factory()->for($this->branch)->create();
        $gate = Gate::forUser($manager);

        $this->assertFalse($gate->allows('refund', $order));
        $this->assertFalse($gate->allows('cancel', $order));

        $this->grantPermission($manager, 'orders.view');
        $this->grantPermission($manager, 'orders.refund');
        $this->grantPermission($manager, 'orders.cancel');

        $this->assertTrue($gate->allows('refund', $order));
        $this->assertTrue($gate->allows('cancel', $order));
        $this->assertFalse($gate->allows('recordPayment', $order));

        $this->grantPermission($manager, 'orders.payment');

        $this->assertTrue($gate->allows('recordPayment', $order));
    }

}
