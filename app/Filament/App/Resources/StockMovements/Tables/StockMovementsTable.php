<?php

namespace App\Filament\App\Resources\StockMovements\Tables;

use App\Enums\StockMovementType;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('inventoryItem.name')
                    ->label('Barang')
                    ->description(fn ($record): ?string => $record->inventoryItem?->sku)
                    ->searchable(),

                TextColumn::make('movement_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (StockMovementType $state): string => $state->label())
                    ->color(fn (StockMovementType $state): string => match ($state) {
                        StockMovementType::In, StockMovementType::Purchase => 'success',
                        StockMovementType::Out, StockMovementType::Usage => 'warning',
                        StockMovementType::Adjustment => 'info',
                    }),

                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('purchase_quantity')
                    ->label('Jumlah Dibeli')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(fn ($record): string => $record->purchase_unit_name_snapshot ? ' '.$record->purchase_unit_name_snapshot : '')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('quantity')
                    ->label('Perubahan Stok')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(fn ($record): string => ' '.($record->inventoryItem?->baseUnit?->code ?? $record->inventoryItem?->unit ?? '')),

                TextColumn::make('unit_cost')
                    ->label('Biaya / Satuan Dasar')
                    ->money('IDR')
                    ->toggleable(),

                TextColumn::make('stock_before')
                    ->label('Stok Sebelum')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('stock_after')
                    ->label('Stok Sesudah')
                    ->numeric(decimalPlaces: 4),

                TextColumn::make('createdBy.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->label('Jenis Mutasi')
                    ->options(collect(StockMovementType::cases())
                        ->mapWithKeys(fn (StockMovementType $type): array => [$type->value => $type->label()])
                        ->all()),
            ]);
    }
}
