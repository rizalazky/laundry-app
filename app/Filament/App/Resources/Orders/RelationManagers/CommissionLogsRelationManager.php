<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommissionLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissionLogs';

    protected static ?string $title = 'Komisi Produksi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->defaultSort('earned_at', 'desc')
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Karyawan')
                    ->placeholder('—'),

                TextColumn::make('productionLog.stage')
                    ->label('Tahap')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'washer' => 'Cuci',
                        'dryer' => 'Kering',
                        'ironing' => 'Setrika',
                        'packing' => 'Packing',
                        default => $state ?? '—',
                    }),

                TextColumn::make('orderItem.service_name')
                    ->label('Layanan'),

                TextColumn::make('amount')
                    ->label('Komisi')
                    ->money('IDR', locale: 'id'),

                TextColumn::make('earned_at')
                    ->label('Diperoleh')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ]);
    }
}
