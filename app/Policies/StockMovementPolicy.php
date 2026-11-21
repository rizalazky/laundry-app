<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('stock.view');
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        return $user->hasPermissionTo('stock.view')
            && (int) $user->tenant_id === (int) $stockMovement->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('stock.manage');
    }

    public function update(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockMovement $stockMovement): bool
    {
        return false;
    }
}
