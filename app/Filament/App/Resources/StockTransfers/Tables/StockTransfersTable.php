<?php

namespace App\Filament\App\Resources\StockTransfers\Tables;

use App\Actions\Inventory\ManageStockTransfer;
use App\Models\StockTransfer;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StockTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('reference')->label('No. Distribusi')->searchable()->copyable(),
                TextColumn::make('transfer_type')->label('Jenis')->badge()->formatStateUsing(fn (string $state): string => $state === 'return' ? 'Pengembalian' : 'Distribusi'),
                TextColumn::make('sourceBranch.name')->label('Dari'),
                TextColumn::make('destinationBranch.name')->label('Ke'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => match ($state) { 'received' => 'success', 'shipped' => 'info', 'cancelled' => 'danger', default => 'warning' })
                    ->formatStateUsing(fn (string $state): string => match ($state) { 'requested' => 'Diminta', 'shipped' => 'Dikirim', 'received' => 'Diterima', 'cancelled' => 'Dibatalkan', default => $state }),
                TextColumn::make('requested_at')->label('Tanggal Permintaan')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('requestedBy.name')->label('Diminta Oleh'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['requested' => 'Diminta', 'shipped' => 'Dikirim', 'received' => 'Diterima']),
                SelectFilter::make('transfer_type')->options(['distribution' => 'Distribusi', 'return' => 'Pengembalian']),
            ])
            ->recordActions([
                Action::make('ship')
                    ->label('Kirim')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->status === 'requested' && Auth::user()?->hasPermissionTo('stock.manage') === true)
                    ->action(fn (StockTransfer $record) => app(ManageStockTransfer::class)->ship(Auth::user(), $record)),

                Action::make('receive')
                    ->label('Terima')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->status === 'shipped' && Auth::user()?->hasPermissionTo('stock.manage') === true)
                    ->action(fn (StockTransfer $record) => app(ManageStockTransfer::class)->receive(Auth::user(), $record)),
            ]);
    }
}
