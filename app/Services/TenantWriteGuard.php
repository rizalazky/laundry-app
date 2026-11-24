<?php

namespace App\Services;

use App\Models\User;
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

        $modelTenantId = $model->getAttribute('tenant_id');

        if ($modelTenantId === null || (int) $modelTenantId !== (int) $user->tenant_id) {
            return;
        }

        $tenant = $user->tenant;

        if ($tenant !== null) {
            app(EntitlementService::class)->assertCanWrite($tenant);
        }
    }
}
