<?php

namespace App\Actions\Accounting;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class FinanceActionAuthorization
{
    /**
     * Resolve an active branch and enforce the authenticated actor's finance
     * permission and branch assignment before a financial mutation is posted.
     *
     * Unauthenticated calls remain available to trusted internal jobs and
     * system workflows; authenticated requests must always pass this boundary.
     *
     * @throws DomainException
     */
    public function authorizeBranch(int $tenantId, int $branchId): Branch
    {
        $branch = Branch::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($branchId)
            ->first();

        if ($branch === null) {
            throw new DomainException('Cabang tidak ditemukan, nonaktif, atau bukan milik tenant ini.');
        }

        $authenticatedUserId = Auth::id();

        if ($authenticatedUserId === null) {
            return $branch;
        }

        // Resolve authorization from persisted state, not a potentially stale
        // user model already cached by the current request.
        $actor = User::query()->find($authenticatedUserId);

        $tenant = Tenant::query()->find($tenantId);

        if (
            $actor === null
            || ! $actor->is_active
            || $actor->tenant_id === null
            || (int) $actor->tenant_id !== $tenantId
            || ! $actor->hasPermissionTo('finance.manage')
            || $tenant === null
        ) {
            throw new DomainException('Anda tidak memiliki izin untuk mencatat transaksi keuangan pada tenant ini.');
        }

        try {
            app(EntitlementService::class)->assertCanWrite($tenant);
        } catch (AuthorizationException $exception) {
            // Preserve the existing finance action exception contract.
            throw new DomainException($exception->getMessage(), 0, $exception);
        }

        if (
            $actor->role !== 'tenant_owner'
            && ! $actor->branches()
                ->whereKey($branchId)
                ->where('branches.tenant_id', $tenantId)
                ->where('branches.is_active', true)
                ->exists()
        ) {
            throw new DomainException('Anda tidak memiliki akses ke cabang ini.');
        }

        return $branch;
    }
}
