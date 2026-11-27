<?php

namespace App\Actions\Payroll;

use App\Models\Payroll;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use DomainException;
use Illuminate\Support\Facades\Auth;

class PayrollAuthorization
{
    public function authorizeManager(User $actor): User
    {
        if (Auth::hasUser() && (int) Auth::id() !== (int) $actor->getKey()) {
            throw new DomainException('Payroll hanya dapat dikelola oleh akun yang sedang masuk.');
        }

        // Never authorize from caller-supplied role or tenant attributes.
        $actor = User::query()->find($actor->getKey());

        if (
            $actor === null
            || ! $actor->is_active
            || $actor->tenant_id === null
            || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
            || ! $actor->hasPermissionTo('payroll.manage')
        ) {
            throw new DomainException('Anda tidak memiliki izin untuk mengelola payroll.');
        }

        $tenant = Tenant::query()->find($actor->tenant_id);

        if ($tenant === null) {
            throw new DomainException('Tenant payroll tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);

        return $actor;
    }

    public function authorizeEmployee(User $actor, int $employeeId, bool $requireActive = true): User
    {
        $employee = User::query()
            ->whereKey($employeeId)
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('role', ['branch_manager', 'cashier', 'operator', 'courier'])
            ->when($requireActive, fn ($query) => $query->where('is_active', true))
            ->first();

        if ($employee === null) {
            throw new DomainException('Karyawan tidak ditemukan atau tidak termasuk dalam tenant ini.');
        }

        if ($actor->role === 'branch_manager') {
            $managedBranchIds = $actor->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            // Include inactive assignments in this comparison so a manager cannot
            // process staff who are also attached to an out-of-scope branch.
            $employeeBranchIds = $employee->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->pluck('branches.id');

            if (
                $employeeBranchIds->isEmpty()
                || $employeeBranchIds->diff($managedBranchIds)->isNotEmpty()
                || $employeeBranchIds->intersect($managedBranchIds)->isEmpty()
                || ! in_array($employee->role, ['cashier', 'operator', 'courier'], true)
            ) {
                throw new DomainException('Karyawan berada di luar cakupan cabang yang Anda kelola.');
            }
        }

        return $employee;
    }

    public function authorizePayroll(User $actor, Payroll $payroll): Payroll
    {
        $payroll = Payroll::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $actor->tenant_id)
            ->whereKey($payroll->getKey())
            ->first();

        if ($payroll === null) {
            throw new DomainException('Data payroll tidak ditemukan.');
        }

        $this->authorizeEmployee($actor, (int) $payroll->user_id, false);

        return $payroll;
    }
}
