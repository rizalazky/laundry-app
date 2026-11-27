<?php

namespace App\Actions\EmployeeLoans;

use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\Auth;

class EmployeeLoanAuthorization
{
    public function authorizeManager(User $actor): User
    {
        if (Auth::hasUser() && (int) Auth::id() !== (int) $actor->getKey()) {
            throw new DomainException('Aksi kasbon hanya dapat dilakukan oleh akun yang sedang masuk.');
        }

        // Never authorize from caller-supplied role or tenant attributes.
        $actor = User::query()->find($actor->getKey());

        if (
            $actor === null
            || ! $actor->is_active
            || $actor->tenant_id === null
            || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
            || ! $actor->hasPermissionTo('staff.manage')
        ) {
            throw new DomainException('Anda tidak memiliki izin untuk mengelola kasbon karyawan.');
        }

        $tenant = Tenant::query()->find($actor->tenant_id);

        if ($tenant === null) {
            throw new DomainException('Tenant kasbon tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);

        return $actor;
    }

    public function authorizeEmployee(User $actor, User $employee, bool $requireActive = true): User
    {
        $employee = User::query()
            ->whereKey($employee->getKey())
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('role', ['branch_manager', 'cashier', 'operator'])
            ->when($requireActive, fn ($query) => $query->where('is_active', true))
            ->first();

        if ($employee === null) {
            throw new DomainException('Karyawan tidak ditemukan atau tidak termasuk dalam tenant aktif.');
        }

        if ($actor->role === 'branch_manager') {
            $managedBranchIds = $actor->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            $employeeBranchIds = $employee->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            if (
                $employeeBranchIds->isEmpty()
                || $employeeBranchIds->diff($managedBranchIds)->isNotEmpty()
                || $employeeBranchIds->intersect($managedBranchIds)->isEmpty()
                || ! in_array($employee->role, ['cashier', 'operator'], true)
            ) {
                throw new DomainException('Karyawan berada di luar cakupan cabang yang Anda kelola.');
            }
        }

        return $employee;
    }
}
