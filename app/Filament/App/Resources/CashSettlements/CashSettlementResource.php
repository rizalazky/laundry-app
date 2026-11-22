<?php

namespace App\Filament\App\Resources\CashSettlements;

use App\Filament\App\Resources\CashSettlements\Pages\CreateCashSettlement;
use App\Filament\App\Resources\CashSettlements\Pages\ListCashSettlements;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashSettlement;
use App\Models\EdcMachine;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CashSettlementResource extends Resource
{
    protected static ?string $model = CashSettlement::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 13;
    protected static ?string $navigationLabel = 'Setoran & Settlement';
    protected static ?string $modelLabel = 'Settlement';
    protected static ?string $pluralModelLabel = 'Setoran & Settlement';

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
        $query = parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->with(['branch', 'targetBank', 'edcMachine', 'creator']);

        if ($user?->role !== 'tenant_owner') {
            $query->where('branch_id', \Filament\Facades\Filament::getTenant()?->getKey());
        }

        return $query->latest('settled_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Detail Setoran')
                ->columns(2)
                ->schema([
                    Select::make('source_type')
                        ->label('Jenis Setoran')
                        ->options([
                            CashSettlement::SOURCE_OUTLET_CASH => 'Setoran Kas Outlet',
                            CashSettlement::SOURCE_WORKSHOP_CASH => 'Setoran Kas Workshop',
                            CashSettlement::SOURCE_EDC => 'Settlement EDC',
                        ])
                        ->native(false)
                        ->live()
                        ->required(),
                    Select::make('branch_id')
                        ->label('Lokasi')
                        ->options(fn (Get $get): array => Branch::query()
                            ->where('is_active', true)
                            ->when(
                                $get('source_type') === CashSettlement::SOURCE_WORKSHOP_CASH,
                                fn (Builder $query) => $query->where('is_workshop', true),
                                fn (Builder $query) => $query->where('is_workshop', false),
                            )
                            ->when(
                                Auth::user()?->role !== 'tenant_owner',
                                fn (Builder $query) => $query->whereKey(\Filament\Facades\Filament::getTenant()?->getKey()),
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('target_bank_id')
                        ->label('Rekening Bank Tujuan')
                        ->options(fn (): array => BankAccount::query()
                            ->where('is_active', true)
                            ->orderBy('bank_name')
                            ->get()
                            ->mapWithKeys(fn (BankAccount $account): array => [
                                $account->id => $account->bank_name.' — '.$account->account_number,
                            ])->all())
                        ->searchable()
                        ->required(),
                    Select::make('edc_machine_id')
                        ->label('Mesin EDC')
                        ->options(fn (Get $get): array => EdcMachine::query()
                            ->where('is_active', true)
                            ->where('branch_id', $get('branch_id'))
                            ->where('bank_account_id', $get('target_bank_id'))
                            ->orderBy('terminal_name')
                            ->pluck('terminal_name', 'id')
                            ->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC)
                        ->required(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC)
                        ->dehydrated(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC),
                    TextInput::make('gross_amount')
                        ->label('Nominal Gross')
                        ->numeric()
                        ->minValue(0.01)
                        ->step('0.01')
                        ->required(),
                    TextInput::make('mdr_amount')
                        ->label('MDR Aktual')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->default('0.00')
                        ->visible(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC)
                        ->required(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC)
                        ->dehydrated(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC),
                    TextInput::make('net_amount')
                        ->label('Nominal Net Diterima')
                        ->numeric()
                        ->minValue(0.01)
                        ->step('0.01')
                        ->visible(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC)
                        ->required(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC)
                        ->dehydrated(fn (Get $get): bool => $get('source_type') === CashSettlement::SOURCE_EDC),
                    TextInput::make('reference_number')
                        ->label('Nomor Referensi / Bukti Setoran')
                        ->required()
                        ->maxLength(100),
                    DateTimePicker::make('settled_at')
                        ->label('Tanggal Settlement')
                        ->default(now())
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('settled_at')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('branch.name')->label('Lokasi')->sortable(),
                TextColumn::make('source_type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CashSettlement::SOURCE_OUTLET_CASH => 'Kas Outlet',
                        CashSettlement::SOURCE_WORKSHOP_CASH => 'Kas Workshop',
                        CashSettlement::SOURCE_EDC => 'EDC',
                        default => $state,
                    }),
                TextColumn::make('gross_amount')->label('Gross')->money('IDR'),
                TextColumn::make('mdr_amount')->label('MDR')->money('IDR'),
                TextColumn::make('net_amount')->label('Net Diterima')->money('IDR'),
                TextColumn::make('targetBank.bank_name')->label('Bank Tujuan'),
                TextColumn::make('edcMachine.terminal_name')->label('Terminal')->placeholder('—'),
                TextColumn::make('reference_number')->label('Referensi')->searchable(),
                TextColumn::make('creator.name')->label('Dicatat Oleh')->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('source_type')->label('Jenis Setoran')->options([
                    CashSettlement::SOURCE_OUTLET_CASH => 'Kas Outlet',
                    CashSettlement::SOURCE_WORKSHOP_CASH => 'Kas Workshop',
                    CashSettlement::SOURCE_EDC => 'EDC',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSettlements::route('/'),
            'create' => CreateCashSettlement::route('/create'),
        ];
    }
}
