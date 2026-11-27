<?php

namespace App\Actions\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class OrderActionAuthorization
{
    /**
     * Reloads the actor and branch from the database so callers cannot bypass
     * authorization with stale or manually constructed model instances.
     *
     * @param  list<string>  $allowedRoles
     */
    public function authorizeBranch(User $user, Branch $branch, array $allowedRoles, bool $requireActiveBusiness = true): Branch
    {
        $actor = $this->canonicalActor($user);

        if ($actor === null || ! $actor->is_active || ! in_array($actor->role, $allowedRoles, true) || $actor->tenant_id === null) {
            throw new DomainException('Anda tidak memiliki izin untuk melakukan tindakan ini.');
        }

        $authorizedBranch = Branch::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($branch->getKey())
            ->where('tenant_id', $actor->tenant_id)
            ->first();

        if ($authorizedBranch === null || ($requireActiveBusiness && ! $authorizedBranch->is_active)) {
            throw new DomainException('Cabang tidak ditemukan atau sedang tidak aktif.');
        }

        $tenant = Tenant::query()->find($authorizedBranch->tenant_id);

        if ($tenant === null) {
            throw new DomainException('Tenant tidak ditemukan.');
        }

        if ($requireActiveBusiness) {
            $this->assertTenantCanWrite($tenant);
        }

        if ($actor->role !== 'tenant_owner' && ! $actor->branches()
            ->whereKey($authorizedBranch->getKey())
            ->where('branches.tenant_id', $actor->tenant_id)
            ->when($requireActiveBusiness, fn ($query) => $query->where('branches.is_active', true))
            ->exists()) {
            throw new DomainException('Anda tidak memiliki akses ke cabang ini.');
        }

        return $authorizedBranch;
    }

    /**
     * @param  list<string>  $allowedRoles
     */
    public function authorizeOrder(
        User $user,
        Order $order,
        array $allowedRoles,
        bool $requireActiveBusiness = true,
        bool $allowMappedWorkshopAccess = false,
    ): Order {
        $actor = $this->canonicalActor($user);

        $lockedOrder = Order::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($order->getKey())
            ->first();

        if ($lockedOrder === null) {
            throw new DomainException('Order tidak ditemukan.');
        }

        $branch = Branch::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($lockedOrder->branch_id)
            ->first();

        if ($branch === null || (int) $branch->tenant_id !== (int) $lockedOrder->tenant_id) {
            throw new DomainException('Cabang order tidak valid.');
        }

        if ($allowMappedWorkshopAccess && $actor->role === 'operator') {
            if (! in_array($actor->role, $allowedRoles, true) || (int) $actor->tenant_id !== (int) $lockedOrder->tenant_id) {
                throw new DomainException('Anda tidak memiliki izin untuk melakukan tindakan ini.');
            }

            if ($requireActiveBusiness && ! $branch->is_active) {
                throw new DomainException('Cabang order tidak ditemukan atau sedang tidak aktif.');
            }

            $tenant = Tenant::query()->find($lockedOrder->tenant_id);

            if ($tenant === null) {
                throw new DomainException('Tenant tidak ditemukan.');
            }

            if ($requireActiveBusiness) {
                $this->assertTenantCanWrite($tenant);
            }

            $allowedBranchIds = $lockedOrder->workshop_id !== null
                ? [$lockedOrder->workshop_id]
                : [$branch->getKey()];

            $operatorHasLocationAccess = $actor->branches()
                ->whereIn('branches.id', $allowedBranchIds)
                ->where('branches.tenant_id', $lockedOrder->tenant_id)
                ->when($requireActiveBusiness, fn ($query) => $query->where('branches.is_active', true))
                ->exists();

            if (! $operatorHasLocationAccess) {
                throw new DomainException('Anda tidak memiliki akses ke outlet atau workshop yang menangani order ini.');
            }

            return $lockedOrder;
        }

        $this->authorizeBranch($actor, $branch, $allowedRoles, $requireActiveBusiness);

        return $lockedOrder;
    }

    private function assertTenantCanWrite(Tenant $tenant): void
    {
        try {
            app(EntitlementService::class)->assertCanWrite($tenant);
        } catch (AuthorizationException $exception) {
            // Keep the action-layer exception contract while using the canonical entitlement decision.
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
    }

    private function canonicalActor(User $user): User
    {
        if (Auth::hasUser() && (int) Auth::id() !== (int) $user->getKey()) {
            throw new DomainException('Anda tidak memiliki izin untuk melakukan tindakan ini.');
        }

        $actor = User::query()->find($user->getKey());

        if ($actor === null) {
            throw new DomainException('Anda tidak memiliki izin untuk melakukan tindakan ini.');
        }

        return $actor;
    }
}
