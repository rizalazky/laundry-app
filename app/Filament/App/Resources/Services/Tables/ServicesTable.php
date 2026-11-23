<?php

namespace App\Filament\App\Resources\Services\Tables;

use App\Enums\ServiceUnit;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Layanan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Satuan')
                    ->formatStateUsing(fn (ServiceUnit $state): string => $state->label()),

                TextColumn::make('price')
                    ->label('Harga Standar')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('commission_value')
                    ->label('Komisi')
                    ->formatStateUsing(fn ($state, $record): string => $record->commission_method === 'percentage'
                        ? number_format((float) $state, 2, ',', '.').' %'
                        : 'Rp '.number_format((float) $state, 0, ',', '.').' / '.$record->unit->label()),

                TextColumn::make('estimated_hours')
                    ->label('Estimasi')
                    ->suffix(' jam')
                    ->placeholder('—'),

                TextColumn::make('branch_prices_count')
                    ->label('Harga Khusus')
                    ->counts('branchPrices')
                    ->suffix(' cabang'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('unit')
                    ->label('Satuan')
                    ->options(ServiceUnit::options()),

                TernaryFilter::make('is_active')
                    ->label('Status'),
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
