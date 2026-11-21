<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->hasPermissionTo('suppliers.view')
            && (int) $user->tenant_id === (int) $supplier->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasPermissionTo('suppliers.manage')
            && (int) $user->tenant_id === (int) $supplier->tenant_id;
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermissionTo('suppliers.manage');
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
