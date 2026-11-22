<?php

namespace App\Filament\App\Resources\Attendances;

use App\Filament\App\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static string|UnitEnum|null $navigationGroup = 'Karyawan & SDM';
    protected static ?int $navigationSort = 20;
    protected static ?string $recordTitleAttribute = 'id';
    protected static ?string $navigationLabel = 'Presensi';
    protected static ?string $modelLabel = 'Presensi';
    protected static ?string $pluralModelLabel = 'Presensi';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->is_active || $user->tenant_id === null) {
            return false;
        }

        if (in_array($user->role, ['tenant_owner', 'branch_manager'], true)) {
            return $user->hasPermissionTo('staff.view');
        }

        return in_array($user->role, ['cashier', 'operator'], true);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        $query = parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->with(['branch', 'user']);

        if ($user?->role === 'branch_manager') {
            $managedBranchIds = $user->branches()
                ->where('branches.tenant_id', $user->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            return $query->whereIn('branch_id', $managedBranchIds);
        }

        if ($user !== null && $user->role !== 'tenant_owner') {
            $query->where('user_id', $user->getKey());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clock_in', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->sortable(),

                TextColumn::make('clock_in')
                    ->label('Masuk')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('clock_out')
                    ->label('Keluar')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Belum clock-out')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'present' ? 'Hadir' : $state),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendances::route('/'),
        ];
    }
}
