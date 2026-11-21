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
            && (int) $user->tenant_id === (int) $stockOpname->tenant_id;
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
