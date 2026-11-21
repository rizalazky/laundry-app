<?php

namespace App\Policies;

use App\Models\StockOpname;
use App\Models\User;

class StockOpnamePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('stock.view');
    }

    public function view(User $user, StockOpname $stockOpname): bool
    {
        return $user->hasPermissionTo('stock.view')
            && (int) $user->tenant_id === (int) $stockOpname->tenant_id
            && ($user->role === 'tenant_owner' || $user->branches()
                ->whereKey($stockOpname->location_id)
                ->where('branches.tenant_id', $user->tenant_id)
                ->exists());
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('stock.manage');
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
}
