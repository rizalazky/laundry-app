<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $supplier);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->isOwner($user) && $this->isInSameTenant($user, $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    private function isOwner(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && $user->role === 'tenant_owner';
    }

    private function isInSameTenant(User $user, Supplier $supplier): bool
    {
        return (int) $user->tenant_id === (int) $supplier->tenant_id;
    }
}
