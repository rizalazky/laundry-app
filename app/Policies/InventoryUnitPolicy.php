<?php

namespace App\Policies;

use App\Models\InventoryUnit;
use App\Models\User;

class InventoryUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, InventoryUnit $inventoryUnit): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $inventoryUnit);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, InventoryUnit $inventoryUnit): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $inventoryUnit);
    }

    public function delete(User $user, InventoryUnit $inventoryUnit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, InventoryUnit $inventoryUnit): bool
    {
        return false;
    }

    public function forceDelete(User $user, InventoryUnit $inventoryUnit): bool
    {
        return false;
    }

    private function isOwner(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && $user->role === 'tenant_owner';
    }

    private function isInSameTenant(User $user, InventoryUnit $inventoryUnit): bool
    {
        return (int) $user->tenant_id === (int) $inventoryUnit->tenant_id;
    }
}
