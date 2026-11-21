<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Tenancy\EditBranchProfile;
use App\Filament\App\Pages\Tenancy\RegisterBranch;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BranchPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_without_view_permission_cannot_view_branch_profile(): void
    {
        [$tenant, $branch, $manager] = $this->context();
        $this->actingAs($manager);

        $this->assertFalse(Gate::forUser($manager)->allows('viewAny', Branch::class));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $branch));
        $this->assertFalse(EditBranchProfile::canView($branch));
    }

    public function test_branch_manager_with_view_permission_can_view_only_assigned_branches(): void
    {
        [$tenant, $branch, $manager] = $this->context();
        $this->actingAs($manager);
        $otherBranch = Branch::factory()->for($tenant)->create();

        $this->grantPermission($manager, 'branches.view');

        $gate = Gate::forUser($manager);

        $this->assertTrue($gate->allows('viewAny', Branch::class));
        $this->assertTrue($gate->allows('view', $branch));
        $this->assertFalse($gate->allows('view', $otherBranch));
        $this->assertTrue(EditBranchProfile::canView($branch));
        $this->assertFalse(EditBranchProfile::canView($otherBranch));
    }

    public function test_branch_manager_needs_view_and_manage_permissions_to_update_assigned_branch(): void
    {
        [, $branch, $manager] = $this->context();
        $this->grantPermission($manager, 'branches.view');

        $gate = Gate::forUser($manager);

        $this->assertFalse($gate->allows('update', $branch));

        $this->grantPermission($manager, 'branches.manage');

        $this->assertTrue($gate->allows('update', $branch));
    }

    public function test_branch_manager_cannot_create_a_branch_even_with_manage_permission(): void
    {
        [, , $manager] = $this->context();
        $this->actingAs($manager);
        $this->grantPermission($manager, 'branches.view');
        $this->grantPermission($manager, 'branches.manage');

        $this->assertFalse(Gate::forUser($manager)->allows('create', Branch::class));
        $this->assertFalse(RegisterBranch::canView());
    }

    public function test_tenant_owner_keeps_branch_management_access_without_matrix_assignments(): void
    {
        [$tenant, $branch] = $this->context();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        $gate = Gate::forUser($owner);

        $this->assertTrue($gate->allows('viewAny', Branch::class));
        $this->assertTrue($gate->allows('view', $branch));
        $this->assertTrue($gate->allows('update', $branch));
        $this->assertTrue($gate->allows('create', Branch::class));
        $this->assertTrue(EditBranchProfile::canView($branch));
        $this->assertTrue(RegisterBranch::canView());
    }

    /**
     * @return array{Tenant, Branch, User}
     */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($branch);

        return [$tenant, $branch, $manager];
    }

    private function grantPermission(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Cabang'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'role' => $user->role,
            'permission_id' => $permission->id,
        ]);
    }
}
