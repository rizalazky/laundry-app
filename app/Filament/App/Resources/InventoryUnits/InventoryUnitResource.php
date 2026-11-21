<?php

namespace App\Filament\App\Resources\InventoryUnits;

use App\Filament\App\Resources\InventoryUnits\Pages\CreateInventoryUnit;
use App\Filament\App\Resources\InventoryUnits\Pages\EditInventoryUnit;
use App\Filament\App\Resources\InventoryUnits\Pages\ListInventoryUnits;
use App\Filament\App\Resources\InventoryUnits\Schemas\InventoryUnitForm;
use App\Filament\App\Resources\InventoryUnits\Tables\InventoryUnitsTable;
use App\Models\InventoryUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class InventoryUnitResource extends Resource
{
    protected static ?string $model = InventoryUnit::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';
    protected static UnitEnum|string|null $navigationGroup = 'Inventori';
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Satuan Inventori';
    protected static ?string $modelLabel = 'Satuan Inventori';
    protected static ?string $pluralModelLabel = 'Satuan Inventori';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return InventoryUnitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryUnitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryUnits::route('/'),
            'create' => CreateInventoryUnit::route('/create'),
            'edit' => EditInventoryUnit::route('/{record}/edit'),
        ];
    }
}
