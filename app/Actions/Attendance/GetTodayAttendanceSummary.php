<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\User;

class GetTodayAttendanceSummary
{
    /**
     * @return array{present: int, on_duty: int, clocked_out: int}
     */
    public function handle(User $user): array
    {
        $user = User::query()->find($user->getKey());

        if ($user === null || ! $user->is_active || $user->tenant_id === null) {
            return [
                'present' => 0,
                'on_duty' => 0,
                'clocked_out' => 0,
            ];
        }

        $query = Attendance::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereDate('clock_in', today());

        if ($user->role === 'branch_manager') {
            $managedBranchIds = $user->branches()
                ->where('branches.tenant_id', $user->tenant_id)
                ->where('branches.is_active', true)
                ->select('branches.id');

            $query->whereIn('branch_id', $managedBranchIds);
        } elseif ($user->role !== 'tenant_owner') {
            $query->where('user_id', $user->getKey());
        }

        $present = (clone $query)->distinct()->count('user_id');
        $onDuty = (clone $query)
            ->whereNull('clock_out')
            ->distinct()
            ->count('user_id');

        return [
            'present' => $present,
            'on_duty' => $onDuty,
            'clocked_out' => max(0, $present - $onDuty),
        ];
    }
}
