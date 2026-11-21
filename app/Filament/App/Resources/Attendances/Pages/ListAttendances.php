<?php

namespace App\Filament\App\Resources\Attendances\Pages;

use App\Actions\Attendance\ClockIn;
use App\Actions\Attendance\ClockOut;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Models\Branch;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clockIn')
                ->label('Absen Masuk')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->color('success')
                ->visible(fn (): bool => Filament::getTenant() instanceof Branch)
                ->action(function (): void {
                    $user = Auth::user();
                    $branch = Filament::getTenant();

                    if (! $user instanceof User || ! $branch instanceof Branch) {
                        Notification::make()
                            ->title('Pilih cabang aktif terlebih dahulu.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        app(ClockIn::class)->handle($user, $branch);

                        Notification::make()
                            ->title('Presensi masuk berhasil dicatat.')
                            ->success()
                            ->send();
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Presensi masuk gagal.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('clockOut')
                ->label('Absen Keluar')
                ->icon('heroicon-o-arrow-left-on-rectangle')
                ->color('warning')
                ->visible(fn (): bool => Filament::getTenant() instanceof Branch)
                ->action(function (): void {
                    $user = Auth::user();
                    $branch = Filament::getTenant();

                    if (! $user instanceof User || ! $branch instanceof Branch) {
                        Notification::make()
                            ->title('Pilih cabang aktif terlebih dahulu.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        app(ClockOut::class)->handle($user, $branch);

                        Notification::make()
                            ->title('Presensi keluar berhasil dicatat.')
                            ->success()
                            ->send();
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Presensi keluar gagal.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
