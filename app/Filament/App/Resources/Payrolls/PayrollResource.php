<?php

namespace App\Filament\App\Resources\Payrolls;

use App\Filament\App\Resources\Payrolls\Pages\ListPayrolls;
use App\Models\Payroll;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = 'Karyawan & SDM';
    protected static ?int $navigationSort = 40;
    protected static ?string $recordTitleAttribute = 'period_month';
    protected static ?string $navigationLabel = 'Penggajian';
    protected static ?string $modelLabel = 'Payroll';
    protected static ?string $pluralModelLabel = 'Payroll';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('payroll.view');
    }

    public static function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('payroll.manage');
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
            ->whereIn('role', $actor->role === 'branch_manager'
                ? ['cashier', 'operator', 'courier']
                : ['branch_manager', 'cashier', 'operator', 'courier']);

        if ($actor->role === 'branch_manager') {
            $managedBranchIds = $actor->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            $query
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
            ->with(['employee', 'creator', 'loanRepayments']);

        if ($actor?->role === 'branch_manager') {
            $managedBranchIds = $actor->branches()
                ->where('branches.tenant_id', $actor->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            $query->whereHas('employee.branches', fn (Builder $branches) => $branches->whereIn('branches.id', $managedBranchIds))
                ->whereDoesntHave('employee.branches', fn (Builder $branches) => $branches->whereNotIn('branches.id', $managedBranchIds));
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
            ->defaultSort('period_month', 'desc')
            ->columns([
                TextColumn::make('employee.name')->label('Karyawan')->searchable()->sortable(),
                TextColumn::make('period_month')->label('Periode')->sortable(),
                TextColumn::make('base_salary')->label('Gaji Pokok')->money('IDR', locale: 'id'),
                TextColumn::make('commission_total')->label('Total Komisi')->money('IDR', locale: 'id'),
                TextColumn::make('loan_deduction')->label('Potongan Kasbon')->money('IDR', locale: 'id'),
                TextColumn::make('net_salary')->label('Gaji Bersih')->money('IDR', locale: 'id')->weight('bold'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? 'Dibayar' : 'Draft'),
                TextColumn::make('paid_at')->label('Tanggal Dibayar')->dateTime('d M Y H:i')->placeholder('Belum dibayar'),
            ])
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayrolls::route('/')];
    }
}
