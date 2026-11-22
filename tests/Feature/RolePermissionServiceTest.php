<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RolePermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_are_scoped_to_the_tenant_and_replaced_atomically(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewOrders = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);
        $createOrders = Permission::query()->create([
            'name' => 'Buat Pesanan',
            'slug' => 'orders.create',
            'group' => 'Pesanan',
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $service = new RolePermissionService();

        $service->sync($tenant, 'cashier', [$viewOrders->id, $createOrders->id]);
        $service->sync($tenant, 'cashier', [$viewOrders->id]);

        $this->assertSame([$viewOrders->id], $service->permissionIdsFor($tenant, 'cashier'));
        $this->assertSame([], $service->permissionIdsFor($otherTenant, 'cashier'));
        $this->assertDatabaseCount('role_permissions', 1);
    }

    public function test_invalid_permission_ids_do_not_remove_existing_assignments(): void
    {
        $tenant = Tenant::factory()->create();
        $permission = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $service = new RolePermissionService();
        $service->sync($tenant, 'cashier', [$permission->id]);

        try {
            $service->sync($tenant, 'cashier', [999999]);
            $this->fail('Expected validation exception was not thrown.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('role_permissions', [
                'tenant_id' => $tenant->id,
                'role' => 'cashier',
                'permission_id' => $permission->id,
            ]);
        }
    }

    public function test_unauthenticated_and_non_owner_users_cannot_change_permission_matrix(): void
    {
        $tenant = Tenant::factory()->create();
        $service = new RolePermissionService();

        try {
            $service->sync($tenant, 'cashier', []);
            $this->fail('Unauthenticated callers must not change tenant permissions.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('role_permissions', 0);
        }

        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $this->actingAs($cashier);

        try {
            $service->sync($tenant, 'cashier', []);
            $this->fail('Non-owner tenant staff must not change the permission matrix.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('role_permissions', 0);
        }
    }

    public function test_tenant_owner_permissions_cannot_be_restricted_by_the_matrix(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->assertTrue($owner->hasPermissionTo('any.permission'));
    }

    public function test_non_owner_user_can_check_an_explicitly_assigned_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $permission = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);
        $user = User::factory()->for($tenant)->withRole('cashier')->create();

        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);

        $this->assertTrue($user->hasPermissionTo('orders.view'));
        $this->assertFalse($user->hasPermissionTo('orders.cancel'));
    }

    public function test_inactive_users_never_have_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('tenant_owner')->create([
            'is_active' => false,
        ]);

        $this->assertFalse($user->hasPermissionTo('dashboard.view'));
    }
}
