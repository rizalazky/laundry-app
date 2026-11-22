<?php

namespace App\Filament\App\Resources\MemberTiers\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MemberTiersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Jenis Member')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('price')
                    ->label('Harga Pendaftaran')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('duration_days')
                    ->label('Masa Berlaku')
                    ->suffix(' hari')
                    ->sortable(),

                TextColumn::make('discount_percent')
                    ->label('Diskon')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('memberships_count')
                    ->label('Jumlah Pendaftaran')
                    ->counts('memberships'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn ($record): bool => ! $record->memberships()->exists()),
            ]);
    }
}
