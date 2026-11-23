<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use App\Enums\CourierTaskStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CourierTasksRelationManager extends RelationManager
{
    protected static string $relationship = 'courierTasks';

    protected static ?string $title = 'Tugas Pickup & Delivery';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('task_type')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('task_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),

                TextColumn::make('courier.name')
                    ->label('Kurir'),

                TextColumn::make('address')
                    ->label('Alamat')
                    ->limit(50)
                    ->tooltip(fn ($record): string => $record->address),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (CourierTaskStatus $state): string => $state->color())
                    ->formatStateUsing(fn (CourierTaskStatus $state): string => $state->label()),

                TextColumn::make('started_at')
                    ->label('Mulai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                TextColumn::make('completed_at')
                    ->label('Selesai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ]);
    }
}
