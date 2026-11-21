<?php

namespace App\Filament\App\Resources\EmployeeLoans;

use App\Filament\App\Resources\EmployeeLoans\Pages\ListEmployeeLoans;
use App\Models\EmployeeLoan;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EmployeeLoanResource extends Resource
{
    protected static ?string $model = EmployeeLoan::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static UnitEnum|string|null $navigationGroup = 'Karyawan';
    protected static ?string $recordTitleAttribute = 'id';
    protected static ?string $navigationLabel = 'Kasbon Karyawan';
    protected static ?string $modelLabel = 'Kasbon Karyawan';
    protected static ?string $pluralModelLabel = 'Kasbon Karyawan';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('staff.view');
    }

    public static function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('staff.manage');
    }

    public static function manageableEmployeeOptions(): array
    {
        $actor = Auth::user();

        if (! $actor || ! static::canManage()) {
            return [];
        }

        $query = User::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('is_active', true)
            ->whereIn('role', ['branch_manager', 'cashier', 'operator']);

        if ($actor->role === 'branch_manager') {
            $managedBranchIds = $actor->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            $query
                ->whereIn('role', ['cashier', 'operator'])
                ->whereHas('branches', fn (Builder $branches) => $branches->whereIn('branches.id', $managedBranchIds))
                ->whereDoesntHave('branches', fn (Builder $branches) => $branches->whereNotIn('branches.id', $managedBranchIds));
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = Auth::user();

        $query = parent::getEloquentQuery()
            ->where('tenant_id', $actor?->tenant_id)
            ->with(['user', 'creator'])
            ->withCount('repayments');

        if ($actor?->role === 'branch_manager') {
            $managedBranchIds = $actor->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            $query
                ->whereHas('user.branches', fn (Builder $branches) => $branches->whereIn('branches.id', $managedBranchIds))
                ->whereDoesntHave('user.branches', fn (Builder $branches) => $branches->whereNotIn('branches.id', $managedBranchIds));
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
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Nominal Kasbon')
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('remaining_amount')
                    ->label('Sisa Kasbon')
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('repayments_count')
                    ->label('Jumlah Cicilan')
                    ->counts('repayments'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? 'Lunas' : 'Aktif'),

                TextColumn::make('issued_at')
                    ->label('Tanggal Kasbon')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeLoans::route('/'),
        ];
    }
}
