<?php

namespace App\Filament\App\Resources\BankAccounts;

use App\Filament\App\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\App\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\BankAccount;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 11;
    protected static ?string $navigationLabel = 'Rekening Bank';
    protected static ?string $modelLabel = 'Rekening Bank';
    protected static ?string $pluralModelLabel = 'Rekening Bank';

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
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with('account')
            ->orderBy('bank_name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Rekening')
                ->columns(2)
                ->schema([
                    TextInput::make('bank_name')->label('Nama Bank')->required()->maxLength(255),
                    TextInput::make('account_number')->label('Nomor Rekening')->required()->maxLength(100),
                    TextInput::make('holder_name')->label('Nama Pemilik Rekening')->required()->maxLength(255),
                    Select::make('coa_id')
                        ->label('Akun COA')
                        ->options(fn (): array => ChartOfAccount::query()
                            ->where('is_active', true)
                            ->where('account_type', 'asset')
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (ChartOfAccount $account): array => [
                                $account->id => $account->code.' — '.$account->name,
                            ])->all())
                        ->searchable()
                        ->required(),
                    Toggle::make('is_active')->label('Rekening Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')->label('Bank')->searchable()->sortable(),
                TextColumn::make('account_number')->label('Nomor Rekening')->searchable(),
                TextColumn::make('holder_name')->label('Atas Nama')->searchable(),
                TextColumn::make('account.code')->label('Kode COA'),
                TextColumn::make('account.name')->label('Akun COA'),
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
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
        ];
    }
}
