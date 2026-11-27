<?php

namespace App\Filament\App\Resources\Investors;

use App\Filament\App\Resources\Investors\Pages\CreateInvestor;
use App\Filament\App\Resources\Investors\Pages\EditInvestor;
use App\Filament\App\Resources\Investors\Pages\ListInvestors;
use App\Models\Branch;
use App\Models\Investor;
use BackedEnum;
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

class InvestorResource extends Resource
{
    protected static ?string $model = Investor::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 20;
    protected static ?string $navigationLabel = 'Data Investor';
    protected static ?string $modelLabel = 'Investor';
    protected static ?string $pluralModelLabel = 'Data Investor';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->role === 'tenant_owner'
            && $user->tenant_id !== null && $user->hasPermissionTo('investors.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->role === 'tenant_owner'
            && (Auth::user()?->hasPermissionTo('investors.manage') ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canCreate()
            && (int) $record->tenant_id === (int) Auth::user()?->tenant_id;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with('branch')
            ->orderBy('name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Investor')->columns(2)->schema([
                TextInput::make('name')->label('Nama Investor')->required()->maxLength(255),
                Select::make('branch_id')
                    ->label('Cabang Investasi')
                    ->options(fn (): array => Branch::query()
                        ->where('tenant_id', Auth::user()?->tenant_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                TextInput::make('capital_amount')
                    ->label('Modal Investasi')
                    ->numeric()->minValue(0.01)->step('0.01')->prefix('Rp')->required(),
                TextInput::make('share_percent')
                    ->label('Persentase Bagi Hasil')
                    ->numeric()->minValue(0.01)->maxValue(100)->step('0.01')->suffix('%')->required(),
                Toggle::make('is_active')->label('Investor Aktif')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama Investor')->searchable()->sortable(),
            TextColumn::make('branch.name')->label('Cabang')->sortable(),
            TextColumn::make('capital_amount')->label('Modal')->money('IDR')->sortable(),
            TextColumn::make('share_percent')->label('Bagi Hasil')->suffix('%')->sortable(),
            TextColumn::make('is_active')->label('Status')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->recordActions([
            \Filament\Actions\EditAction::make()->visible(fn (): bool => static::canCreate()),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestors::route('/'),
            'create' => CreateInvestor::route('/create'),
            'edit' => EditInvestor::route('/{record}/edit'),
        ];
    }
}
