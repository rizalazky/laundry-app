<?php

namespace App\Filament\App\Resources\ChartOfAccounts;

use App\Filament\App\Resources\ChartOfAccounts\Pages\CreateChartOfAccount;
use App\Filament\App\Resources\ChartOfAccounts\Pages\ListChartOfAccounts;
use App\Models\ChartOfAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 10;
    protected static ?string $navigationLabel = 'Data Akun (COA)';
    protected static ?string $modelLabel = 'Akun';
    protected static ?string $pluralModelLabel = 'Daftar Akun';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('finance.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('finance.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate() && ! $record->is_system;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->withCount(['journalEntries', 'bankAccounts'])
            ->orderBy('code');
    }

    public static function form(Schema $schema): Schema
    {
        $isReferenced = fn (?ChartOfAccount $record): bool => $record !== null
            && ($record->journalEntries()->exists() || $record->bankAccounts()->exists());

        return $schema->components([
            Section::make('Informasi Akun')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Kode Akun')
                        ->required()
                        ->maxLength(30)
                        ->disabled(fn (?ChartOfAccount $record): bool => ($record?->is_system ?? false) || $isReferenced($record)),
                    TextInput::make('name')
                        ->label('Nama Akun')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?ChartOfAccount $record): bool => $record?->is_system ?? false),
                    Select::make('account_type')
                        ->label('Tipe Akun')
                        ->options([
                            'asset' => 'Aset',
                            'liability' => 'Liabilitas',
                            'equity' => 'Ekuitas',
                            'revenue' => 'Pendapatan',
                            'expense' => 'Beban',
                        ])
                        ->required()
                        ->native(false)
                        ->disabled(fn (?ChartOfAccount $record): bool => ($record?->is_system ?? false) || $isReferenced($record)),
                    Toggle::make('is_active')
                        ->label('Akun Aktif')
                        ->default(true)
                        ->disabled(fn (?ChartOfAccount $record): bool => ($record?->is_system ?? false) || $isReferenced($record)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama Akun')->searchable()->sortable(),
                TextColumn::make('account_type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'asset' => 'Aset',
                        'liability' => 'Liabilitas',
                        'equity' => 'Ekuitas',
                        'revenue' => 'Pendapatan',
                        'expense' => 'Beban',
                        default => $state,
                    }),
                TextColumn::make('is_system')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sistem' : 'Kustom')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                TextColumn::make('journal_entries_count')->label('Baris Jurnal'),
                TextColumn::make('bank_accounts_count')->label('Rekening Bank'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('account_type')
                    ->label('Tipe Akun')
                    ->options([
                        'asset' => 'Aset',
                        'liability' => 'Liabilitas',
                        'equity' => 'Ekuitas',
                        'revenue' => 'Pendapatan',
                        'expense' => 'Beban',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ChartOfAccount $record): bool => static::canEdit($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChartOfAccounts::route('/'),
            'create' => CreateChartOfAccount::route('/create'),
        ];
    }
}
