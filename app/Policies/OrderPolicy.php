<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    private const TENANT_ROLES = ['tenant_owner', 'branch_manager', 'cashier', 'operator'];

    private const CASHIER_ROLES = ['tenant_owner', 'branch_manager', 'cashier'];

    private const MANAGER_ROLES = ['tenant_owner', 'branch_manager'];

    public function viewAny(User $user): bool
    {
        return $this->isTenantStaff($user, self::TENANT_ROLES)
            && $user->hasPermissionTo('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->canAccessOrder($user, $order, self::TENANT_ROLES);
    }

    public function create(User $user): bool
    {
        return $this->isTenantStaff($user, self::CASHIER_ROLES)
            && $user->hasPermissionTo('orders.create');
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function restore(User $user, Order $order): bool
    {
        return false;
    }

    public function forceDelete(User $user, Order $order): bool
    {
        return false;
    }

    public function changeStatus(User $user, Order $order): bool
    {
        return $this->canAccessOrder($user, $order, self::TENANT_ROLES);
    }

    public function recordPayment(User $user, Order $order): bool
    {
        return $this->canAccessOrder($user, $order, self::CASHIER_ROLES);
    }

    public function refund(User $user, Order $order): bool
    {
        return $this->canAccessOrder($user, $order, self::MANAGER_ROLES);
    }

    /**
     * @param  list<string>  $roles
     */
    private function isTenantStaff(User $user, array $roles): bool
    {
        return $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, $roles, true);
    }

    /**
     * @param  list<string>  $roles
     */
    private function canAccessOrder(User $user, Order $order, array $roles): bool
    {
        return $this->isTenantStaff($user, $roles)
            && (int) $user->tenant_id === (int) $order->tenant_id
            && $user->canAccessTenant($order->branch);
    }
}
