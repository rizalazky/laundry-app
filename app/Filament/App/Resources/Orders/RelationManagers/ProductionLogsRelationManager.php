<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductionLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'productionLogs';

    protected static ?string $title = 'Riwayat Produksi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('stage')
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('stage')
                    ->label('Tahap')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'washer' => 'Cuci',
                        'dryer' => 'Kering',
                        'ironing' => 'Setrika',
                        'packing' => 'Packing',
                        default => $state,
                    }),

                TextColumn::make('started_at')
                    ->label('Mulai')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Selesai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Sedang berlangsung'),

                TextColumn::make('worker.name')
                    ->label('Petugas')
                    ->placeholder('—'),

                TextColumn::make('machine_code')
                    ->label('Mesin')
                    ->placeholder('—'),
            ]);
    }
}
