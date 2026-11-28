<?php

namespace Tests\Feature;

use App\Filament\App\Pages\RolePermissions;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_save_permissions_for_a_role_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = \App\Models\Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $permission = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(RolePermissions::class)
            ->set('role', 'cashier')
            ->set('selectedPermissions', [(string) $permission->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('role_permissions', [
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);
    }

    public function test_saving_one_tenant_matrix_does_not_change_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = \App\Models\Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $permission = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);
        RolePermission::query()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(RolePermissions::class)
            ->set('role', 'cashier')
            ->set('selectedPermissions', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('role_permissions', [
            'tenant_id' => $otherTenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);
    }

    public function test_branch_manager_cannot_access_permission_matrix_page(): void
    {
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();

        $this->actingAs($manager);

        $this->assertFalse(RolePermissions::canAccess());
    }


    public function test_role_permission_service_rejects_a_stale_deactivated_owner_before_raw_matrix_writes(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $existingPermission = Permission::query()->create([
            'name' => 'Lihat Pesanan',
            'slug' => 'orders.view',
            'group' => 'Pesanan',
        ]);
        $newPermission = Permission::query()->create([
            'name' => 'Kelola Pesanan',
            'slug' => 'orders.manage',
            'group' => 'Pesanan',
        ]);

        $existing = RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'permission_id' => $existingPermission->id,
        ]);

        $this->actingAs($owner);

        // Keep Auth's in-memory owner active while persisting deactivation.
        User::query()->whereKey($owner->getKey())->update(['is_active' => false]);

        try {
            app(\App\Services\RolePermissionService::class)->sync(
                $tenant,
                'cashier',
                [$newPermission->id],
            );

            $this->fail('A deactivated owner must not replace the permission matrix through the service directly.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseHas('role_permissions', [
                'id' => $existing->id,
                'tenant_id' => $tenant->id,
                'role' => 'cashier',
                'permission_id' => $existingPermission->id,
            ]);
            $this->assertDatabaseMissing('role_permissions', [
                'tenant_id' => $tenant->id,
                'role' => 'cashier',
                'permission_id' => $newPermission->id,
            ]);
        }
    }

    public function test_role_change_loads_the_selected_roles_existing_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = \App\Models\Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $permission = Permission::query()->create([
            'name' => 'Kelola Pelanggan',
            'slug' => 'customers.manage',
            'group' => 'Pelanggan',
        ]);
        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'operator',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(RolePermissions::class)
            ->set('role', 'operator')
            ->assertSet('selectedPermissions', [$permission->id]);
    }

    public function test_permission_matrix_page_rejects_livewire_requests_after_owner_is_deactivated(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = \App\Models\Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $component = Livewire::test(RolePermissions::class);

        $owner->update(['is_active' => false]);

        $component
            ->call('getPermissionGroups')
            ->assertForbidden();
    }
}
