<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $inventoryItem);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $inventoryItem);
    }

    public function delete(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->update($user, $inventoryItem);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function restore(User $user, InventoryItem $inventoryItem): bool
    {
        return false;
    }

    public function forceDelete(User $user, InventoryItem $inventoryItem): bool
    {
        return false;
    }

    private function isOwner(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && $user->role === 'tenant_owner';
    }

    private function isInSameTenant(User $user, InventoryItem $inventoryItem): bool
    {
        return (int) $user->tenant_id === (int) $inventoryItem->tenant_id;
    }
}
