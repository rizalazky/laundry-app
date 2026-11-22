<?php

namespace App\Filament\App\Resources\Raffles\Tables;

use App\Actions\Raffles\IssueRaffleTickets;
use App\Filament\App\Resources\Raffles\RaffleResource;
use App\Models\Customer;
use App\Models\Raffle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RafflesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama Undian')->searchable()->sortable(),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    Raffle::STATUS_ACTIVE => 'Aktif',
                    Raffle::STATUS_COMPLETED => 'Selesai',
                    Raffle::STATUS_CANCELLED => 'Dibatalkan',
                    default => 'Draft',
                }),
            TextColumn::make('starts_at')->label('Mulai')->dateTime('d M Y H:i')->placeholder('—'),
            TextColumn::make('ends_at')->label('Berakhir')->dateTime('d M Y H:i')->placeholder('—'),
            TextColumn::make('tickets_count')->label('Tiket Terbit')->counts('tickets'),
        ])->filters([
            SelectFilter::make('status')->label('Status')->options([
                Raffle::STATUS_DRAFT => 'Draft',
                Raffle::STATUS_ACTIVE => 'Aktif',
                Raffle::STATUS_COMPLETED => 'Selesai',
                Raffle::STATUS_CANCELLED => 'Dibatalkan',
            ]),
        ])->recordActions([
            Action::make('issueTickets')
                ->label('Terbitkan Tiket')
                ->icon('heroicon-o-ticket')
                ->visible(fn (Raffle $record): bool => RaffleResource::canCreate() && $record->status === Raffle::STATUS_ACTIVE)
                ->schema([
                    Select::make('customer_id')->label('Pelanggan')
                        ->options(fn (): array => Customer::query()->where('tenant_id', Auth::user()?->tenant_id)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    TextInput::make('quantity')->label('Jumlah Tiket')->numeric()->integer()->minValue(1)->maxValue(100)->default(1)->required(),
                ])
                ->action(function (Raffle $record, array $data): void {
                    try {
                        $tickets = app(IssueRaffleTickets::class)->handle(
                            Auth::user(),
                            (int) $record->id,
                            (int) $data['customer_id'],
                            (int) $data['quantity'],
                        );

                        Notification::make()->title(count($tickets).' tiket berhasil diterbitkan.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Penerbitan tiket gagal.')
                            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                    }
                }),
            EditAction::make()->visible(fn (Raffle $record): bool => RaffleResource::canEdit($record)),
            DeleteAction::make()->visible(fn (Raffle $record): bool => RaffleResource::canDelete($record)),
        ]);
    }
}
