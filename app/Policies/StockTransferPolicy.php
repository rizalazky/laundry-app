<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('stock.view');
    }

    public function view(User $user, StockTransfer $record): bool
    {
        return $user->hasPermissionTo('stock.view')
            && (int) $user->tenant_id === (int) $record->tenant_id
            && ($user->role === 'tenant_owner' || (
                $user->branches()->whereKey($record->source_branch_id)
                    ->where('branches.tenant_id', $user->tenant_id)->exists()
                && $user->branches()->whereKey($record->destination_branch_id)
                    ->where('branches.tenant_id', $user->tenant_id)->exists()
            ));
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('stock.manage');
    }

    public function update(User $user, StockTransfer $record): bool
    {
        return false;
    }

    public function delete(User $user, StockTransfer $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
