<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use App\Services\EntitlementService;

class BranchPolicy
{
    private const STAFF_ROLES = ['tenant_owner', 'branch_manager'];

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user)
            && $user->hasPermissionTo('branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->viewAny($user)
            && (int) $user->tenant_id === (int) $branch->tenant_id
            && $user->canAccessTenant($branch);
    }

    public function create(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && $user->role === 'tenant_owner'
            && $user->hasPermissionTo('branches.manage')
            && app(EntitlementService::class)->canCreateBranch($user->tenant);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->view($user, $branch)
            && $user->hasPermissionTo('branches.manage');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isTenantStaff(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, self::STAFF_ROLES, true);
    }
}