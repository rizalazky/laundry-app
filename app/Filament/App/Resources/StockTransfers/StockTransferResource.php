<?php

namespace App\Filament\App\Resources\StockTransfers;

use App\Filament\App\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\App\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Models\StockTransfer;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use BackedEnum;
use UnitEnum;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static UnitEnum|string|null $navigationGroup = 'Inventori';
    protected static ?string $navigationLabel = 'Distribusi Stok';
    protected static ?string $modelLabel = 'Distribusi Stok';
    protected static ?string $pluralModelLabel = 'Distribusi Stok';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['sourceBranch', 'destinationBranch', 'requestedBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\App\Resources\StockTransfers\Schemas\StockTransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\App\Resources\StockTransfers\Tables\StockTransfersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
        ];
    }
}
