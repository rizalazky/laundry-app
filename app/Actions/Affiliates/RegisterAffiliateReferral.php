<?php

namespace App\Actions\Affiliates;

use App\Models\AffiliateReferral;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterAffiliateReferral
{
    public function handle(int $referrerTenantId, int $referredTenantId, float $commissionRate): AffiliateReferral
    {
        $authenticatedUser = Auth::user();
        $actor = $authenticatedUser instanceof User
            ? User::query()->find($authenticatedUser->getKey())
            : null;

        if (
            $actor === null
            || ! $actor->is_active
            || $actor->role !== 'tenant_owner'
            || (int) $actor->tenant_id !== $referrerTenantId
            || ! $actor->hasPermissionTo('affiliates.manage')
        ) {
            throw new AuthorizationException('Hanya owner tenant yang berhak mendaftarkan referral pada tenant sendiri.');
        }

        $referrer = Tenant::query()->findOrFail($referrerTenantId);
        app(EntitlementService::class)->assertCanWrite($referrer);

        if ($referrerTenantId === $referredTenantId) {
            throw ValidationException::withMessages([
                'referred_tenant_id' => 'Tenant tidak dapat menjadi referral untuk dirinya sendiri.',
            ]);
        }

        if ($commissionRate < 0 || $commissionRate > 100) {
            throw ValidationException::withMessages([
                'commission_rate' => 'Komisi harus berada di antara 0 sampai 100 persen.',
            ]);
        }

        return DB::transaction(function () use ($referrerTenantId, $referredTenantId, $commissionRate): AffiliateReferral {
            Tenant::query()->whereKey($referrerTenantId)->lockForUpdate()->firstOrFail();
            Tenant::query()->whereKey($referredTenantId)->lockForUpdate()->firstOrFail();

            if (AffiliateReferral::query()->where('referred_tenant_id', $referredTenantId)->exists()) {
                throw ValidationException::withMessages([
                    'referred_tenant_id' => 'Tenant ini sudah terdaftar melalui referral lain.',
                ]);
            }

            return AffiliateReferral::query()->create([
                'referrer_tenant_id' => $referrerTenantId,
                'referred_tenant_id' => $referredTenantId,
                'commission_rate' => $commissionRate,
                'status' => AffiliateReferral::STATUS_PENDING,
                'referred_at' => now(),
            ]);
        });
    }
}
