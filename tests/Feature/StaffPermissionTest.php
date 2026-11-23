<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Filament\App\Resources\Users\UserResource;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_view_permission_is_required_to_access_staff_management(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $this->actingAs($manager);

        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse($manager->can('viewAny', User::class));

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');

        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue($manager->can('viewAny', User::class));
    }

    public function test_branch_manager_requires_staff_manage_permission_for_staff_mutations(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);
        $staff = User::factory()->for($tenant)->withRole('cashier')->create();
        $staff->branches()->attach($branch);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');

        $this->assertTrue($manager->can('view', $staff));
        $this->assertFalse($manager->can('create', User::class));
        $this->assertFalse($manager->can('update', $staff));
        $this->assertFalse($manager->can('delete', $staff));

        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $this->assertTrue($manager->can('create', User::class));
        $this->assertTrue($manager->can('update', $staff));
        $this->assertTrue($manager->can('delete', $staff));
    }

    public function test_branch_manager_cannot_manage_staff_assigned_to_an_unmanaged_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $managedBranch = Branch::factory()->for($tenant)->create();
        $unmanagedBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($managedBranch);
        $staff = User::factory()->for($tenant)->withRole('cashier')->create();
        $staff->branches()->attach([$managedBranch->id, $unmanagedBranch->id]);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $this->assertFalse($manager->can('view', $staff));
        $this->assertFalse($manager->can('update', $staff));
        $this->assertFalse($manager->can('delete', $staff));
    }

    public function test_staff_permissions_are_scoped_to_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        $this->grantPermission($otherTenant, 'branch_manager', 'staff.view');
        $this->actingAs($manager);

        $this->assertFalse($manager->can('viewAny', User::class));
        $this->assertFalse(UserResource::canAccess());
    }

    public function test_tenant_owner_retains_staff_access_and_inactive_managers_are_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create([
            'is_active' => false,
        ]);
        $manager->branches()->attach($branch);
        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $this->assertTrue($owner->can('viewAny', User::class));
        $this->assertTrue($owner->can('create', User::class));
        $this->assertFalse($manager->can('viewAny', User::class));
        $this->assertFalse($manager->can('create', User::class));
    }

    public function test_owner_cannot_assign_staff_to_a_branch_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $ownBranch = Branch::factory()->for($tenant)->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($ownBranch);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Foreign Branch Staff',
                'email' => 'foreign-branch-staff@example.test',
                'password' => 'password123',
                'role' => 'cashier',
                'monthly_base_salary' => '0.00',
                'is_active' => true,
                'branches' => [$foreignBranch->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['branches']);

        $this->assertDatabaseMissing('users', [
            'email' => 'foreign-branch-staff@example.test',
        ]);
    }

    public function test_branch_manager_cannot_assign_staff_to_an_unmanaged_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $managedBranch = Branch::factory()->for($tenant)->create();
        $unmanagedBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($managedBranch);
        $staff = User::factory()->for($tenant)->withRole('cashier')->create();
        $staff->branches()->attach($managedBranch);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $this->actingAs($manager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($managedBranch);

        Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
            ->fillForm(['branches' => [$unmanagedBranch->id]])
            ->call('save')
            ->assertHasFormErrors(['branches']);

        $this->assertDatabaseHas('branch_user', [
            'user_id' => $staff->id,
            'branch_id' => $managedBranch->id,
        ]);
        $this->assertDatabaseMissing('branch_user', [
            'user_id' => $staff->id,
            'branch_id' => $unmanagedBranch->id,
        ]);
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Karyawan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
