<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        return $this->isOwner($user)
            && (int) $user->tenant_id === (int) $stockMovement->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
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

    private function isOwner(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && $user->role === 'tenant_owner';
    }
}
