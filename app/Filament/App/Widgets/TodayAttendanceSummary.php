<?php

namespace App\Filament\App\Widgets;

use App\Actions\Attendance\GetTodayAttendanceSummary;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class TodayAttendanceSummary extends StatsOverviewWidget
{
    protected ?string $heading = 'Ringkasan Presensi Hari Ini';

    protected ?string $description = 'Jumlah karyawan unik berdasarkan presensi hari ini.';

    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = 1;

    protected GetTodayAttendanceSummary $attendanceSummary;

    public function boot(GetTodayAttendanceSummary $attendanceSummary): void
    {
        $this->attendanceSummary = $attendanceSummary;
    }

    public static function canView(): bool
    {
        $user = static::authenticatedActor();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('dashboard.view');
    }

    public function hydrate(): void
    {
        abort_unless(static::canView(), 403);
    }

    private static function authenticatedActor(): ?User
    {
        $authenticatedUserId = Auth::id();

        return $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);
    }

    protected function getStats(): array
    {
        $user = static::authenticatedActor();

        if ($user === null || ! $user->hasPermissionTo('dashboard.view')) {
            return [];
        }

        $summary = $this->attendanceSummary->handle($user);

        return [
            Stat::make('Hadir Hari Ini', $summary['present'])
                ->description('Karyawan yang sudah clock-in')
                ->descriptionIcon('heroicon-m-user-group'),

            Stat::make('Masih Bekerja', $summary['on_duty'])
                ->description('Belum melakukan clock-out')
                ->descriptionIcon('heroicon-m-clock'),

            Stat::make('Sudah Pulang', $summary['clocked_out'])
                ->description('Tidak memiliki presensi terbuka hari ini')
                ->descriptionIcon('heroicon-m-check-circle'),
        ];
    }
}
