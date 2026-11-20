<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Riwayat Pembayaran';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (PaymentType $state): string => $state->label())
                    ->color(fn (PaymentType $state): string => $state->color()),

                TextColumn::make('method')
                    ->label('Metode')
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),

                TextColumn::make('amount')
                    ->label('Nominal')
                    ->state(fn (Payment $record): string => $record->type === PaymentType::Refund ? '-'.$record->amount : $record->amount)
                    ->money('IDR', locale: 'id'),

                TextColumn::make('receiver.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),

                TextColumn::make('reference')
                    ->label('Referensi')
                    ->placeholder('—'),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40),
            ]);
    }
}
