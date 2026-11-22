<?php

namespace App\Filament\App\Resources\EmployeeLoans;

use App\Actions\EmployeeLoans\RecordEmployeeLoanRepayment;
use App\Filament\App\Resources\EmployeeLoans\Pages\ListEmployeeLoans;
use App\Models\EmployeeLoan;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EmployeeLoanResource extends Resource
{
    protected static ?string $model = EmployeeLoan::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = 'Karyawan & SDM';
    protected static ?int $navigationSort = 30;
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
            ->with(['user', 'creator', 'repayments.recorder'])
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
                    ->label('Jumlah Cicilan'),

                TextColumn::make('repayments.amount')
                    ->label('Riwayat Nominal Cicilan')
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('repayments.paid_at')
                    ->label('Tanggal Cicilan')
                    ->dateTime('d M Y H:i')
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? 'Lunas' : 'Aktif'),

                TextColumn::make('issued_at')
                    ->label('Tanggal Kasbon')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('recordRepayment')
                    ->label('Catat Cicilan')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (EmployeeLoan $record): bool => static::canManage() && $record->status === 'active')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Nominal Pembayaran')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(9999999999999.99)
                            ->prefix('Rp')
                            ->required(),

                        DateTimePicker::make('paid_at')
                            ->label('Tanggal Pembayaran')
                            ->default(now())
                            ->required(),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2),
                    ])
                    ->action(function (EmployeeLoan $record, array $data): void {
                        try {
                            app(RecordEmployeeLoanRepayment::class)->handle(
                                Auth::user(),
                                (int) $record->getKey(),
                                $data['amount'],
                                Carbon::parse($data['paid_at']),
                                $data['notes'] ?? null,
                            );

                            Notification::make()
                                ->title('Cicilan kasbon berhasil dicatat.')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('Pembayaran gagal dicatat.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeLoans::route('/'),
        ];
    }
}
