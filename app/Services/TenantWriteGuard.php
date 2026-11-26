<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TenantWriteGuard
{
    public function assertModelWriteAllowed(Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return;
        }

        // Subscription and billing records must remain writable for renewal
        // and payment processing while operational data is read-only.
        if (str_starts_with($model::class, 'App\\Models\\Subscription')) {
            return;
        }

        $modelTenantId = $this->resolveTenantId($model);

        if ($modelTenantId === null) {
            return;
        }

        if ((int) $modelTenantId !== (int) $user->tenant_id) {
            throw new AuthorizationException('Anda tidak memiliki akses untuk mengubah data tenant lain.');
        }

        app(EntitlementService::class)->assertCanWrite($user->tenant);
    }

    private function resolveTenantId(Model $model): ?int
    {
        $tenantId = $model->getAttribute('tenant_id');

        if ($tenantId !== null) {
            return (int) $tenantId;
        }

        foreach (['branch_id', 'location_id'] as $branchAttribute) {
            $branchId = $model->getAttribute($branchAttribute);

            if ($branchId === null) {
                continue;
            }

            $branchTenantId = Branch::withoutGlobalScopes()
                ->whereKey($branchId)
                ->value('tenant_id');

            if ($branchTenantId !== null) {
                return (int) $branchTenantId;
            }
        }

        return null;
    }
}
