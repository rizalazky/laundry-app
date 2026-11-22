<?php

namespace App\Filament\App\Resources\EdcMachines;

use App\Filament\App\Resources\EdcMachines\Pages\CreateEdcMachine;
use App\Filament\App\Resources\EdcMachines\Pages\ListEdcMachines;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\EdcMachine;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EdcMachineResource extends Resource
{
    protected static ?string $model = EdcMachine::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 12;
    protected static ?string $navigationLabel = 'Mesin EDC';
    protected static ?string $modelLabel = 'Mesin EDC';
    protected static ?string $pluralModelLabel = 'Mesin EDC';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && $user->hasPermissionTo('finance.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('finance.manage') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        return parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->when(
                $user?->role !== 'tenant_owner',
                fn (Builder $query) => $query->where('branch_id', \Filament\Facades\Filament::getTenant()?->getKey()),
            )
            ->with(['branch', 'bankAccount'])
            ->orderBy('terminal_name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Konfigurasi Mesin EDC')
                ->columns(2)
                ->schema([
                    TextInput::make('terminal_name')->label('Nama Terminal')->required()->maxLength(255),
                    Select::make('branch_id')
                        ->label('Outlet')
                        ->options(fn (): array => Branch::query()
                            ->where('is_active', true)
                            ->where('is_workshop', false)
                            ->when(
                                Auth::user()?->role !== 'tenant_owner',
                                fn (Builder $query) => $query->whereKey(\Filament\Facades\Filament::getTenant()?->getKey()),
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('bank_account_id')
                        ->label('Rekening Tujuan Settlement')
                        ->options(fn (): array => BankAccount::query()
                            ->where('is_active', true)
                            ->orderBy('bank_name')
                            ->get()
                            ->mapWithKeys(fn (BankAccount $account): array => [
                                $account->id => $account->bank_name.' — '.$account->account_number,
                            ])->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('mdr_percent')
                        ->label('Estimasi MDR (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        ->default('0.00')
                        ->required()
                        ->helperText('Hanya estimasi untuk referensi. Settlement harus memakai nilai MDR aktual dari bukti bank.'),
                    Toggle::make('is_active')->label('Mesin Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('terminal_name')->label('Terminal')->searchable()->sortable(),
                TextColumn::make('branch.name')->label('Outlet')->sortable(),
                TextColumn::make('bankAccount.bank_name')->label('Bank'),
                TextColumn::make('bankAccount.account_number')->label('Rekening'),
                TextColumn::make('mdr_percent')->label('Estimasi MDR')->suffix('%'),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => static::canCreate()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdcMachines::route('/'),
            'create' => CreateEdcMachine::route('/create'),
        ];
    }
}
