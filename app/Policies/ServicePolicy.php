<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    private const TENANT_ROLES = ['tenant_owner', 'branch_manager', 'cashier', 'operator'];

    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, self::TENANT_ROLES, true);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->viewAny($user) && $this->isInSameTenant($user, $service);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $service);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function restore(User $user, Service $service): bool
    {
        return false;
    }

    public function forceDelete(User $user, Service $service): bool
    {
        return false;
    }

    private function isOwner(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && $user->role === 'tenant_owner';
    }

    private function isInSameTenant(User $user, Service $service): bool
    {
        return (int) $user->tenant_id === (int) $service->tenant_id;
    }
}
