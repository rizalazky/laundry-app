<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClockOut
{
    public function __construct(
        private AttendanceActionAuthorization $authorization,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws DomainException
     */
    public function handle(User $user, Branch $branch, ?CarbonInterface $clockOutAt = null): Attendance
    {
        return DB::transaction(function () use ($user, $branch, $clockOutAt): Attendance {
            [$actor, $authorizedBranch] = $this->authorization->authorize($user, $branch);

            $attendance = Attendance::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $actor->tenant_id)
                ->where('branch_id', $authorizedBranch->getKey())
                ->where('user_id', $actor->getKey())
                ->whereNull('clock_out')
                ->latest('clock_in')
                ->lockForUpdate()
                ->first();

            if ($attendance === null) {
                throw new DomainException('Tidak ada presensi masuk yang aktif untuk cabang ini.');
            }

            $clockOutAt ??= now();

            if ($clockOutAt->lessThan($attendance->clock_in)) {
                throw new DomainException('Waktu presensi keluar tidak boleh lebih awal dari waktu masuk.');
            }

            $attendance->forceFill(['clock_out' => $clockOutAt])->save();
            $attendance->refresh();

            $this->activityLogger->record($actor, 'attendance.clocked_out', $attendance, [
                'branch_id' => $authorizedBranch->getKey(),
                'clock_in' => $attendance->clock_in?->toISOString(),
                'clock_out' => $attendance->clock_out?->toISOString(),
            ]);

            return $attendance;
        });
    }
}
