<?php

namespace App\Filament\App\Resources\RaffleTickets\Tables;

use App\Models\RaffleTicket;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RaffleTicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('ticket_number')->label('Nomor Tiket')->searchable()->copyable()->sortable(),
            TextColumn::make('raffle.name')->label('Undian')->searchable(),
            TextColumn::make('customer.name')->label('Pelanggan')->searchable(),
            TextColumn::make('issued_at')->label('Diterbitkan')->dateTime('d M Y H:i')->sortable(),
            IconColumn::make('is_winner')->label('Pemenang')->boolean(),
        ])->filters([
            TernaryFilter::make('is_winner')->label('Status Pemenang'),
        ])->recordActions([
            Action::make('toggleWinner')
                ->label(fn (RaffleTicket $record): string => $record->is_winner ? 'Batalkan Pemenang' : 'Tandai Pemenang')
                ->icon('heroicon-o-trophy')
                ->color(fn (RaffleTicket $record): string => $record->is_winner ? 'gray' : 'success')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->hasPermissionTo('raffles.manage') ?? false)
                ->action(function (RaffleTicket $record): void {
                    $record->update(['is_winner' => ! $record->is_winner]);
                    Notification::make()->title('Status pemenang tiket diperbarui.')->success()->send();
                }),
        ]);
    }
}
