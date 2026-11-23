<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_manage_staff_only_inside_own_tenant(): void
    {
        $tenant = $this->createTenant('laundry-one');
        $otherTenant = $this->createTenant('laundry-two');

        $owner = $this->createUser($tenant, 'tenant_owner');
        $staff = $this->createUser($tenant, 'cashier');
        $foreignStaff = $this->createUser($otherTenant, 'cashier');
        $anotherOwner = $this->createUser($tenant, 'tenant_owner');

        $policy = new UserPolicy();

        $this->assertTrue($policy->viewAny($owner));
        $this->assertTrue($policy->update($owner, $staff));
        $this->assertFalse($policy->update($owner, $foreignStaff));
        $this->assertFalse($policy->update($owner, $anotherOwner));
    }

    public function test_branch_manager_can_manage_only_staff_fully_within_assigned_branches(): void
    {
        $tenant = $this->createTenant('laundry-branch-test');
        $otherTenant = $this->createTenant('laundry-branch-foreign');
        $branchA = $tenant->branches()->create([
            'code' => 'A',
            'name' => 'Branch A',
            'is_active' => true,
        ]);
        $branchB = $tenant->branches()->create([
            'code' => 'B',
            'name' => 'Branch B',
            'is_active' => true,
        ]);
        $foreignBranch = $otherTenant->branches()->create([
            'code' => 'EXT',
            'name' => 'Foreign Branch',
            'is_active' => true,
        ]);

        $manager = $this->createUser($tenant, 'branch_manager');
        $cashierA = $this->createUser($tenant, 'cashier');
        $operatorB = $this->createUser($tenant, 'operator');
        $sharedStaff = $this->createUser($tenant, 'cashier');
        $foreignAssignedStaff = $this->createUser($tenant, 'cashier');
        $otherManager = $this->createUser($tenant, 'branch_manager');

        $manager->branches()->attach([$branchA->id, $foreignBranch->id]);
        $cashierA->branches()->attach($branchA);
        $operatorB->branches()->attach($branchB);
        $sharedStaff->branches()->attach([$branchA->id, $branchB->id]);
        $foreignAssignedStaff->branches()->attach($foreignBranch);
        $otherManager->branches()->attach($branchA);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $policy = new UserPolicy();

        $this->assertTrue($policy->viewAny($manager));
        $this->assertTrue($policy->view($manager, $cashierA));
        $this->assertTrue($policy->update($manager, $cashierA));
        $this->assertFalse($policy->view($manager, $operatorB));
        $this->assertFalse($policy->update($manager, $operatorB));
        $this->assertFalse($policy->view($manager, $sharedStaff));
        $this->assertFalse($policy->update($manager, $sharedStaff));
        $this->assertFalse($policy->view($manager, $foreignAssignedStaff));
        $this->assertFalse($policy->update($manager, $foreignAssignedStaff));
        $this->assertFalse($policy->update($manager, $otherManager));
        $this->assertFalse($policy->delete($manager, $manager));
    }

    public function test_branch_manager_can_manage_couriers_assigned_to_their_branches(): void
    {
        $tenant = $this->createTenant('laundry-courier-policy');
        $branch = $tenant->branches()->create([
            'code' => 'COURIER',
            'name' => 'Courier Branch',
            'is_active' => true,
        ]);

        $manager = $this->createUser($tenant, 'branch_manager');
        $courier = $this->createUser($tenant, 'courier');
        $manager->branches()->attach($branch);
        $courier->branches()->attach($branch);

        $this->grantPermission($tenant, 'branch_manager', 'staff.view');
        $this->grantPermission($tenant, 'branch_manager', 'staff.manage');

        $policy = new UserPolicy();

        $this->assertTrue($policy->view($manager, $courier));
        $this->assertTrue($policy->update($manager, $courier));
    }

    public function test_user_can_access_only_active_branches_in_own_tenant(): void
    {
        $tenant = $this->createTenant('laundry-access-test');
        $otherTenant = $this->createTenant('laundry-access-other');

        $ownBranch = $tenant->branches()->create([
            'code' => 'OWN',
            'name' => 'Own Branch',
            'is_active' => true,
        ]);
        $inactiveBranch = $tenant->branches()->create([
            'code' => 'OFF',
            'name' => 'Inactive Branch',
            'is_active' => false,
        ]);
        $foreignBranch = $otherTenant->branches()->create([
            'code' => 'EXT',
            'name' => 'Foreign Branch',
            'is_active' => true,
        ]);

        $manager = $this->createUser($tenant, 'branch_manager');
        $manager->branches()->attach([$ownBranch->id, $inactiveBranch->id, $foreignBranch->id]);

        $this->assertTrue($manager->canAccessTenant($ownBranch));
        $this->assertFalse($manager->canAccessTenant($inactiveBranch));
        $this->assertFalse($manager->canAccessTenant($foreignBranch));
    }

    public function test_inactive_users_cannot_access_any_branch_even_if_they_remain_assigned(): void
    {
        $tenant = $this->createTenant('laundry-inactive-access');
        $branch = $tenant->branches()->create([
            'code' => 'ACTIVE',
            'name' => 'Active Branch',
            'is_active' => true,
        ]);

        $owner = $this->createUser($tenant, 'tenant_owner');
        $manager = $this->createUser($tenant, 'branch_manager');
        $manager->branches()->attach($branch);

        $owner->update(['is_active' => false]);
        $manager->update(['is_active' => false]);

        $this->assertFalse($owner->canAccessTenant($branch));
        $this->assertFalse($manager->canAccessTenant($branch));
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

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => $slug,
            'slug' => $slug,
            'owner_email' => $slug . '@example.test',
            'plan' => 'starter',
            'max_branches' => 3,
            'is_active' => true,
        ]);
    }

    private function createUser(Tenant $tenant, string $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $role,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
