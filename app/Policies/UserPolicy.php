<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->tenant_id !== null;
    }

    public function view(User $user, User $model): bool
    {
        if (! $this->isInSameTenant($user, $model)) {
            return false;
        }

        return $user->role === 'tenant_owner' || $this->canManageRole($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $model): bool
    {
        if (! $this->isInSameTenant($user, $model) || ! $this->canManageRole($user, $model)) {
            return false;
        }

        return $model->role !== 'tenant_owner'
            && $model->getKey() !== $user->getKey();
    }

    public function delete(User $user, User $model): bool
    {
        if (! $this->isInSameTenant($user, $model) || ! $this->canManageRole($user, $model)) {
            return false;
        }

        return ! in_array($model->role, ['tenant_owner', 'super_admin'], true)
            && $model->getKey() !== $user->getKey();
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    private function isInSameTenant(User $user, User $model): bool
    {
        return $user->tenant_id !== null
            && (int) $user->tenant_id === (int) $model->tenant_id
            && $model->role !== 'super_admin';
    }

    private function canManageRole(User $user, User $model): bool
    {
        if ($user->role === 'tenant_owner') {
            return true;
        }

        if ($user->role !== 'branch_manager' || ! in_array($model->role, ['cashier', 'operator'], true)) {
            return false;
        }

        $managedBranchIds = $user->branches()->pluck('branches.id');

        return $managedBranchIds->isNotEmpty()
            && $model->branches()->whereIn('branches.id', $managedBranchIds)->exists();
    }
}
