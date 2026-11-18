<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    private const TENANT_ROLES = ['tenant_owner', 'branch_manager', 'cashier', 'operator'];

    private const MANAGER_ROLES = ['tenant_owner', 'branch_manager'];

    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, self::TENANT_ROLES, true);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user) && (int) $user->tenant_id === (int) $customer->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, self::MANAGER_ROLES, true);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->create($user) && (int) $user->tenant_id === (int) $customer->tenant_id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return false;
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return false;
    }
}
