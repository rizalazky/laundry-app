<?php

namespace App\Filament\App\Resources\Promos\Tables;

use App\Models\Promo;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromosTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama Promo')->searchable()->sortable(),
            TextColumn::make('category')->label('Kategori')->badge()
                ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            TextColumn::make('reward_type')->label('Benefit')->formatStateUsing(
                fn (string $state, Promo $record): string => $state === Promo::REWARD_PERCENT
                    ? number_format((float) $record->reward_value, 2, ',', '.').'%'
                    : 'Rp '.number_format((float) $record->reward_value, 0, ',', '.')
            ),
            TextColumn::make('start_at')->label('Mulai')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
            TextColumn::make('end_at')->label('Berakhir')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
            TextColumn::make('vouchers_count')->label('Voucher')->counts('vouchers'),
        ])->filters([
            SelectFilter::make('category')->label('Kategori')->options([
                Promo::CATEGORY_BASIC => 'Basic',
                Promo::CATEGORY_GENERAL => 'General',
            ]),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->visible(fn (Promo $record): bool => ! $record->vouchers()->exists()),
        ]);
    }
}
