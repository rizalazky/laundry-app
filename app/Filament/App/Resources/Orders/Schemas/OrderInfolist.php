<?php

namespace App\Filament\App\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_no')->label('No. Nota')->copyable(),
                        TextEntry::make('customer.name')->label('Pelanggan'),
                        TextEntry::make('customer.phone')->label('Telepon')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                            ->color(fn (OrderStatus $state): string => $state->color()),
                        TextEntry::make('order_date')->label('Tanggal Order')->dateTime('d M Y H:i'),
                        TextEntry::make('due_at')->label('Estimasi Selesai')->dateTime('d M Y H:i')->placeholder('—'),
                        TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
                    ]),

                Section::make('Layanan')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('service_name')->label('Layanan'),
                                TextEntry::make('quantity')
                                    ->label('Jumlah')
                                    ->formatStateUsing(fn ($state, $record): string => rtrim(rtrim((string) $state, '0'), '.').' '.$record->unit->value),
                                TextEntry::make('unit_price')->label('Harga')->money('IDR', locale: 'id'),
                                TextEntry::make('subtotal')->label('Subtotal')->money('IDR', locale: 'id'),
                            ]),
                    ]),

                Section::make('Tagihan')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal')->label('Subtotal')->money('IDR', locale: 'id'),
                        TextEntry::make('discount')->label('Diskon')->money('IDR', locale: 'id'),
                        TextEntry::make('total')->label('Total')->money('IDR', locale: 'id')->weight('bold'),
                        TextEntry::make('paid_amount')->label('Sudah Dibayar')->money('IDR', locale: 'id'),
                        TextEntry::make('balance_due')
                            ->label('Sisa Tagihan')
                            ->state(fn (Order $record): string => $record->balanceDue())
                            ->money('IDR', locale: 'id')
                            ->weight('bold'),
                        TextEntry::make('payment_status')
                            ->label('Status Pembayaran')
                            ->badge()
                            ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                            ->color(fn (PaymentStatus $state): string => $state->color()),
                    ]),
            ]);
    }
}
