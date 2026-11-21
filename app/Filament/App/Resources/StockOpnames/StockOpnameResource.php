<?php

namespace App\Filament\App\Resources\StockOpnames;

use App\Filament\App\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\App\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\App\Resources\StockOpnames\Tables\StockOpnamesTable;
use App\Models\StockOpname;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static UnitEnum|string|null $navigationGroup = 'Inventori';
    protected static ?string $navigationLabel = 'Stok Opname';
    protected static ?string $modelLabel = 'Stok Opname';
    protected static ?string $pluralModelLabel = 'Stok Opname';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['branch', 'createdBy', 'finalizedBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\App\Resources\StockOpnames\Schemas\StockOpnameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockOpnamesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnames::route('/'),
            'create' => CreateStockOpname::route('/create'),
        ];
    }
}
