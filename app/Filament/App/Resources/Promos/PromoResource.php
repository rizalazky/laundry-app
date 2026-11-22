<?php

namespace App\Filament\App\Resources\Promos;

use App\Filament\App\Resources\Promos\Pages\CreatePromo;
use App\Filament\App\Resources\Promos\Pages\EditPromo;
use App\Filament\App\Resources\Promos\Pages\ListPromos;
use App\Filament\App\Resources\Promos\Schemas\PromoForm;
use App\Filament\App\Resources\Promos\Tables\PromosTable;
use App\Models\Promo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class PromoResource extends Resource
{
    protected static ?string $model = Promo::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan & CRM';
    protected static ?int $navigationSort = 30;
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Promo';
    protected static ?string $modelLabel = 'Promo';
    protected static ?string $pluralModelLabel = 'Promo';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager'], true)
            && $user->hasPermissionTo('promotions.view');
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('promotions.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return static::canCreate() && ! $record->vouchers()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Auth::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return PromoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromos::route('/'),
            'create' => CreatePromo::route('/create'),
            'edit' => EditPromo::route('/{record}/edit'),
        ];
    }
}
