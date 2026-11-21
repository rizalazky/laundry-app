<?php

namespace App\Policies;

use App\Models\StockOpname;
use App\Models\User;

class StockOpnamePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, StockOpname $stockOpname): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $stockOpname->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, StockOpname $stockOpname): bool
    {
        return false;
    }

    public function delete(User $user, StockOpname $stockOpname): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isOwner(User $user): bool
    {
        return $user->is_active && $user->tenant_id !== null && $user->role === 'tenant_owner';
    }
}
