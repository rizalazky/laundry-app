<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.view');
    }

    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->hasPermissionTo('inventory.view')
            && (int) $user->tenant_id === (int) $inventoryItem->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.manage');
    }

    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->hasPermissionTo('inventory.manage')
            && (int) $user->tenant_id === (int) $inventoryItem->tenant_id;
    }

    public function delete(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->update($user, $inventoryItem);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.manage');
    }

    public function restore(User $user, InventoryItem $inventoryItem): bool
    {
        return false;
    }

    public function forceDelete(User $user, InventoryItem $inventoryItem): bool
    {
        return false;
    }
}
