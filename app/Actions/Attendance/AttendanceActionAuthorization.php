<?php

namespace App\Actions\Attendance;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\Auth;

class AttendanceActionAuthorization
{
    /**
     * @return array{User, Branch}
     */
    public function authorize(User $user, Branch $branch): array
    {
        if (Auth::hasUser() && (int) Auth::id() !== (int) $user->getKey()) {
            throw new DomainException('Anda hanya dapat mencatat presensi untuk akun sendiri.');
        }

        $actor = User::query()
            ->lockForUpdate()
            ->find($user->getKey());

        if ($actor === null || ! $actor->is_active || $actor->tenant_id === null) {
            throw new DomainException('Akun tidak aktif atau tidak memiliki tenant.');
        }

        $tenant = Tenant::query()->find($actor->tenant_id);

        if ($tenant === null) {
            throw new DomainException('Tenant presensi tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);

        $authorizedBranch = Branch::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($branch->getKey())
            ->where('tenant_id', $actor->tenant_id)
            ->where('is_active', true)
            ->first();

        if ($authorizedBranch === null || ! $actor->canAccessTenant($authorizedBranch)) {
            throw new DomainException('Anda tidak memiliki akses ke cabang ini.');
        }

        return [$actor, $authorizedBranch];
    }
}
