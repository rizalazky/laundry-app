<?php

namespace App\Actions\CourierTasks;

use App\Enums\CourierTaskStatus;
use App\Models\CourierTask;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateCourierTaskStatus
{
    public function handle(CourierTask $task, CourierTaskStatus $to, User $user): CourierTask
    {
        $actor = User::query()->find($user->getKey());

        if ($actor === null || ! $actor->is_active || (int) $actor->tenant_id !== (int) $task->tenant_id) {
            throw new DomainException('Anda tidak memiliki akses ke tugas kurir ini.');
        }

        return DB::transaction(function () use ($task, $to, $actor): CourierTask {
            $locked = CourierTask::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new DomainException('Anda tidak memiliki akses ke tugas kurir ini.');
            }

            $isAssignedCourier = (int) $locked->courier_id === (int) $actor->getKey();
            $isManager = in_array($actor->role, ['tenant_owner', 'branch_manager'], true);

            if ($isAssignedCourier && $actor->role === 'courier') {
                $hasCurrentBranchAccess = $locked->order()
                    ->whereIn('branch_id', $actor->branches()
                        ->where('branches.tenant_id', $actor->tenant_id)
                        ->where('branches.is_active', true)
                        ->select('branches.id'))
                    ->exists();

                if (! $hasCurrentBranchAccess) {
                    throw new DomainException('Anda tidak lagi ditugaskan ke cabang order ini.');
                }
            }

            if (! $isAssignedCourier && ! $isManager) {
                throw new DomainException('Hanya kurir yang ditugaskan atau pengelola cabang yang dapat memperbarui tugas ini.');
            }

            if ($to !== CourierTaskStatus::Cancelled && ! $isAssignedCourier) {
                throw new DomainException('Hanya kurir yang ditugaskan yang dapat memulai atau menyelesaikan tugas ini.');
            }

            if ($isManager && ! $isAssignedCourier && $actor->role !== 'tenant_owner') {
                $hasBranchAccess = $actor->branches()
                    ->whereKey($locked->order()->value('branch_id'))
                    ->where('branches.tenant_id', $locked->tenant_id)
                    ->where('branches.is_active', true)
                    ->exists();

                if (! $hasBranchAccess) {
                    throw new DomainException('Anda tidak memiliki akses ke cabang tugas ini.');
                }
            }

            if ($to !== CourierTaskStatus::Cancelled && ! $actor->hasPermissionTo('courier_tasks.update')) {
                throw new DomainException('Anda tidak memiliki izin memperbarui tugas kurir.');
            }

            if ($to === CourierTaskStatus::Cancelled && ! $actor->hasPermissionTo('courier_tasks.manage')) {
                throw new DomainException('Anda tidak memiliki izin membatalkan tugas kurir.');
            }

            if ($to === CourierTaskStatus::InProgress && $locked->status !== CourierTaskStatus::Assigned) {
                throw new DomainException('Tugas hanya dapat dimulai dari status Ditugaskan.');
            }

            if ($to === CourierTaskStatus::Completed && $locked->status !== CourierTaskStatus::InProgress) {
                throw new DomainException('Tugas harus berstatus Dalam Perjalanan sebelum diselesaikan.');
            }

            if ($to === CourierTaskStatus::Cancelled) {
                if (! $isManager || in_array($locked->status, [CourierTaskStatus::Completed, CourierTaskStatus::Cancelled], true)) {
                    throw new DomainException('Tugas ini tidak dapat dibatalkan.');
                }
            }

            if (! in_array($to, [CourierTaskStatus::InProgress, CourierTaskStatus::Completed, CourierTaskStatus::Cancelled], true)) {
                throw new DomainException('Perubahan status tugas tidak valid.');
            }

            $now = now();
            $locked->forceFill([
                'status' => $to,
                'started_at' => $to === CourierTaskStatus::InProgress ? $now : $locked->started_at,
                'completed_at' => $to === CourierTaskStatus::Completed ? $now : null,
            ])->save();

            return $locked->refresh();
        });
    }
}
