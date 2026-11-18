<?php

namespace Tests\Feature;

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

        $manager = $this->createUser($tenant, 'branch_manager');
        $cashierA = $this->createUser($tenant, 'cashier');
        $operatorB = $this->createUser($tenant, 'operator');
        $sharedStaff = $this->createUser($tenant, 'cashier');
        $otherManager = $this->createUser($tenant, 'branch_manager');

        $manager->branches()->attach($branchA);
        $cashierA->branches()->attach($branchA);
        $operatorB->branches()->attach($branchB);
        $sharedStaff->branches()->attach([$branchA->id, $branchB->id]);
        $otherManager->branches()->attach($branchA);

        $policy = new UserPolicy();

        $this->assertTrue($policy->viewAny($manager));
        $this->assertTrue($policy->view($manager, $cashierA));
        $this->assertTrue($policy->update($manager, $cashierA));
        $this->assertFalse($policy->view($manager, $operatorB));
        $this->assertFalse($policy->update($manager, $operatorB));
        $this->assertFalse($policy->view($manager, $sharedStaff));
        $this->assertFalse($policy->update($manager, $sharedStaff));
        $this->assertFalse($policy->update($manager, $otherManager));
        $this->assertFalse($policy->delete($manager, $manager));
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
