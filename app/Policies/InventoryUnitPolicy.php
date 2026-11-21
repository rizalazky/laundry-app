<?php

namespace App\Policies;

use App\Models\InventoryUnit;
use App\Models\User;

class InventoryUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.view');
    }

    public function view(User $user, InventoryUnit $inventoryUnit): bool
    {
        return $user->hasPermissionTo('inventory.view')
            && (int) $user->tenant_id === (int) $inventoryUnit->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.manage');
    }

    public function update(User $user, InventoryUnit $inventoryUnit): bool
    {
        return $user->hasPermissionTo('inventory.manage')
            && (int) $user->tenant_id === (int) $inventoryUnit->tenant_id;
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
}
