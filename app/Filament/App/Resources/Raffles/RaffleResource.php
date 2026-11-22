<?php

namespace App\Filament\App\Resources\Raffles;

use App\Filament\App\Resources\Raffles\Pages\CreateRaffle;
use App\Filament\App\Resources\Raffles\Pages\EditRaffle;
use App\Filament\App\Resources\Raffles\Pages\ListRaffles;
use App\Filament\App\Resources\Raffles\Schemas\RaffleForm;
use App\Filament\App\Resources\Raffles\Tables\RafflesTable;
use App\Models\Raffle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class RaffleResource extends Resource
{
    protected static ?string $model = Raffle::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 35;
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Undian';
    protected static ?string $modelLabel = 'Undian';
    protected static ?string $pluralModelLabel = 'Undian';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('raffles.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('raffles.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate() && $record->tickets()->doesntExist();
    }

    public static function canDelete($record): bool
    {
        return static::canCreate() && $record->tickets()->doesntExist();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return RaffleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RafflesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRaffles::route('/'),
            'create' => CreateRaffle::route('/create'),
            'edit' => EditRaffle::route('/{record}/edit'),
        ];
    }
}
