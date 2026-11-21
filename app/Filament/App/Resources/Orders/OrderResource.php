<?php

namespace App\Filament\App\Resources\Orders;

use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\App\Resources\Orders\Pages\ViewOrder;
use App\Filament\App\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\App\Resources\Orders\RelationManagers\StatusLogsRelationManager;
use App\Filament\App\Resources\Orders\Schemas\OrderForm;
use App\Filament\App\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\App\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $recordTitleAttribute = 'invoice_no';

    protected static ?string $navigationLabel = 'Order';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Order';

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasPermissionTo('orders.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermissionTo('orders.create') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
            StatusLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
