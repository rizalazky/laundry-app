<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, StockTransfer $record): bool
    {
        return $this->isOwner($user) && (int) $user->tenant_id === (int) $record->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, StockTransfer $record): bool { return false; }
    public function delete(User $user, StockTransfer $record): bool { return false; }
    public function deleteAny(User $user): bool { return false; }

    private function isOwner(User $user): bool
    {
        return $user->is_active && $user->tenant_id !== null && $user->role === 'tenant_owner';
    }
}
