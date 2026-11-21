<?php

namespace App\\Filament\\App\\Resources\\InventoryItems\\Tables;

use Filament\\Actions\\DeleteAction;
use Filament\\Actions\\DeleteBulkAction;
use Filament\\Actions\\EditAction;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Filters\\SelectFilter;
use Filament\\Tables\\Table;

class InventoryItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama Barang')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item_category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'raw_material' => 'Bahan Baku',
                        'retail_product' => 'Produk Retail',
                        default => $state,
                    }),

                TextColumn::make('current_stock')
                    ->label('Stok Cabang Aktif')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(fn ($record): string => ' '.($record->baseUnit?->code ?? $record->unit ?? ''))
                    ->color(fn ($record): string => (float) ($record->current_stock ?? 0) <= (float) $record->min_stock ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('baseUnit.name')
                    ->label('Satuan Dasar')
                    ->toggleable(),

                TextColumn::make('purchaseUnit.name')
                    ->label('Satuan Pembelian')
                    ->toggleable(),

                TextColumn::make('purchase_unit_conversion_factor')
                    ->label('Konversi')
                    ->numeric(decimalPlaces: 6)
                    ->toggleable(),

                TextColumn::make('min_stock')
                    ->label('Minimum Stok')
                    ->numeric(decimalPlaces: 2),
            ])
            ->filters([
                SelectFilter::make('item_category')
                    ->label('Kategori')
                    ->options([
                        'raw_material' => 'Bahan Baku',
                        'retail_product' => 'Produk Retail',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
