<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClockIn
{
    public function __construct(
        private AttendanceActionAuthorization $authorization,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws DomainException
     */
    public function handle(User $user, Branch $branch, ?CarbonInterface $clockInAt = null): Attendance
    {
        return DB::transaction(function () use ($user, $branch, $clockInAt): Attendance {
            [$actor, $authorizedBranch] = $this->authorization->authorize($user, $branch);

            $hasOpenAttendance = Attendance::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $actor->tenant_id)
                ->where('user_id', $actor->getKey())
                ->whereNull('clock_out')
                ->lockForUpdate()
                ->exists();

            if ($hasOpenAttendance) {
                throw new DomainException('Anda masih memiliki presensi masuk yang belum ditutup.');
            }

            $attendance = Attendance::query()->create([
                'tenant_id' => $actor->tenant_id,
                'branch_id' => $authorizedBranch->getKey(),
                'user_id' => $actor->getKey(),
                'clock_in' => $clockInAt ?? now(),
                'status' => 'present',
            ]);

            $this->activityLogger->record($actor, 'attendance.clocked_in', $attendance, [
                'branch_id' => $authorizedBranch->getKey(),
                'clock_in' => $attendance->clock_in?->toISOString(),
            ]);

            return $attendance;
        });
    }
}
