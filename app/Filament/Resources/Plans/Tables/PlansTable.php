<?php

namespace App\\Filament\\Resources\\Plans\\Tables;

use Filament\\Actions\\EditAction;
use Filament\\Tables\\Columns\\IconColumn;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Filters\\TernaryFilter;
use Filament\\Tables\\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Paket')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('monthly_price')
                    ->label('Bulanan')
                    ->formatStateUsing(fn ($state, $record): string => $record->currency.' '.number_format((float) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('yearly_price')
                    ->label('Tahunan')
                    ->formatStateUsing(fn ($state, $record): string => $state === null
                        ? 'Tidak tersedia'
                        : $record->currency.' '.number_format((float) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('features_count')
                    ->label('Fitur & limit')
                    ->counts('features'),

                IconColumn::make('is_active')
                    ->label('Tampil di katalog')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status katalog'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
