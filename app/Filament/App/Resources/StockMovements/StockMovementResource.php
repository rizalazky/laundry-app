<?php

namespace App\Filament\App\Resources\StockMovements;

use App\Filament\App\Resources\StockMovements\Pages\CreateStockMovement;
use App\Filament\App\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\App\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\App\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static UnitEnum|string|null $navigationGroup = 'Inventori';
    protected static ?string $recordTitleAttribute = 'id';
    protected static ?string $navigationLabel = 'Mutasi Stok';
    protected static ?string $modelLabel = 'Mutasi Stok';
    protected static ?string $pluralModelLabel = 'Mutasi Stok';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['branch', 'inventoryItem.baseUnit', 'supplier', 'createdBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
            'create' => CreateStockMovement::route('/create'),
        ];
    }
}
