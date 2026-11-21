<?php

namespace App\Filament\App\Resources\Customers\RelationManagers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Riwayat Transaksi';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = Auth::user();

                if ($user === null) {
                    return $query->whereRaw('1 = 0');
                }

                $branchIds = $user
                    ->getTenants(Filament::getCurrentPanel())
                    ->modelKeys();

                return $query->whereIn('branch_id', $branchIds);
            })
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('invoice_no')
                    ->label('No. Nota')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('branch.name')
                    ->label('Cabang'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),

                TextColumn::make('payment_status')
                    ->label('Pembayaran')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color()),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('IDR', locale: 'id'),

                TextColumn::make('paid_amount')
                    ->label('Dibayar')
                    ->money('IDR', locale: 'id'),

                TextColumn::make('order_date')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ]);
    }
}
