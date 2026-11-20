<?php

namespace App\Filament\App\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('invoice_no')
                    ->label('No. Nota')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('customer.name')
                    ->label('Pelanggan')
                    ->searchable(),

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
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->label('Dibayar')
                    ->money('IDR', locale: 'id'),

                TextColumn::make('balance_due')
                    ->label('Sisa')
                    ->state(fn (Order $record): string => $record->balanceDue())
                    ->money('IDR', locale: 'id'),

                TextColumn::make('order_date')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('due_at')
                    ->label('Estimasi Selesai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(OrderStatus::options())
                    ->multiple(),

                SelectFilter::make('payment_status')
                    ->label('Pembayaran')
                    ->options(PaymentStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('receipt')
                    ->label('Nota')
                    ->icon('heroicon-o-printer')
                    ->url(fn (Order $record): string => route('orders.receipt', $record))
                    ->openUrlInNewTab(),
            ]);
    }
}
