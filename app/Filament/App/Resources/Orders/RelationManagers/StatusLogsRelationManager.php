<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use App\Enums\OrderStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'statusLogs';

    protected static ?string $title = 'Riwayat Status';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('to_status')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('from_status')
                    ->label('Dari')
                    ->formatStateUsing(fn (?OrderStatus $state): string => $state?->label() ?? '—'),

                TextColumn::make('to_status')
                    ->label('Ke')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),

                TextColumn::make('user.name')
                    ->label('Oleh')
                    ->placeholder('—'),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40),
            ]);
    }
}
