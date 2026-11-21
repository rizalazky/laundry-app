<?php

namespace App\Filament\App\Resources\ActivityLogs;

use App\Filament\App\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static UnitEnum|string|null $navigationGroup = 'Karyawan';
    protected static ?string $recordTitleAttribute = 'action';
    protected static ?string $navigationLabel = 'Log Aktivitas';
    protected static ?string $modelLabel = 'Log Aktivitas';
    protected static ?string $pluralModelLabel = 'Log Aktivitas';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('staff.view');
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = Auth::user();

        $query = parent::getEloquentQuery()
            ->where('tenant_id', $actor?->tenant_id)
            ->with('user');

        if ($actor?->role !== 'branch_manager') {
            return $query;
        }

        $managedBranchIds = $actor->branches()
            ->where('branches.tenant_id', $actor->tenant_id)
            ->where('branches.is_active', true)
            ->pluck('branches.id');

        $visibleStaffIds = User::query()
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('role', ['branch_manager', 'cashier', 'operator'])
            ->whereHas('branches', fn (Builder $branches) => $branches->whereIn('branches.id', $managedBranchIds))
            ->whereDoesntHave('branches', fn (Builder $branches) => $branches->whereNotIn('branches.id', $managedBranchIds))
            ->select('users.id');

        $visibleAttendanceIds = Attendance::query()
            ->where('tenant_id', $actor->tenant_id)
            ->whereIn('branch_id', $managedBranchIds)
            ->select('attendances.id');

        return $query->where(function (Builder $logs) use ($visibleStaffIds, $visibleAttendanceIds): void {
            $logs
                ->whereIn('user_id', $visibleStaffIds)
                ->orWhere(function (Builder $subjects) use ($visibleStaffIds): void {
                    $subjects
                        ->where('subject_type', User::class)
                        ->whereIn('subject_id', $visibleStaffIds);
                })
                ->orWhere(function (Builder $subjects) use ($visibleAttendanceIds): void {
                    $subjects
                        ->where('subject_type', Attendance::class)
                        ->whereIn('subject_id', $visibleAttendanceIds);
                });
        });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Pelaku')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action')
                    ->label('Aktivitas')
                    ->badge()
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label('Objek')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),

                TextColumn::make('subject_id')
                    ->label('ID Objek'),

                TextColumn::make('properties')
                    ->label('Detail')
                    ->formatStateUsing(fn (?array $state): string => $state === null
                        ? '—'
                        : (json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—'))
                    ->wrap(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
