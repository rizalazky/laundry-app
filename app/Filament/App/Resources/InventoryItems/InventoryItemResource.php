<?php

namespace App\Filament\App\Resources\InventoryItems;

use App\Filament\App\Resources\InventoryItems\Pages\CreateInventoryItem;
use App\Filament\App\Resources\InventoryItems\Pages\EditInventoryItem;
use App\Filament\App\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\App\Resources\InventoryItems\Schemas\InventoryItemForm;
use App\Filament\App\Resources\InventoryItems\Tables\InventoryItemsTable;
use App\Models\InventoryItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';
    protected static UnitEnum|string|null $navigationGroup = 'Master Data';
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $navigationLabel = 'Barang Inventori';
    protected static ?string $modelLabel = 'Barang Inventori';
    protected static ?string $pluralModelLabel = 'Barang Inventori';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return InventoryItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
            'create' => CreateInventoryItem::route('/create'),
            'edit' => EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
