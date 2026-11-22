<?php

namespace App\Filament\App\Resources\PointLogs\Tables;

use App\Actions\Loyalty\AwardCustomerPoints;
use App\Models\Customer;
use App\Models\PointLog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PointLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
            TextColumn::make('customer.name')->label('Pelanggan')->searchable()->sortable(),
            TextColumn::make('type')->label('Jenis')->badge()
                ->formatStateUsing(fn (string $state): string => $state === PointLog::TYPE_EARN ? 'Poin Masuk' : 'Penukaran'),
            TextColumn::make('points')->label('Poin')->numeric(),
            TextColumn::make('description')->label('Keterangan')->wrap(),
        ])->filters([
            SelectFilter::make('type')->label('Jenis')->options([
                PointLog::TYPE_EARN => 'Poin Masuk',
                PointLog::TYPE_REDEEM => 'Penukaran',
            ]),
        ])->headerActions([
            Action::make('awardPoints')
                ->label('Tambah Poin')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => Auth::user()?->hasPermissionTo('loyalty.manage') ?? false)
                ->schema([
                    Select::make('customer_id')->label('Pelanggan')
                        ->options(fn (): array => Customer::query()->where('tenant_id', Auth::user()?->tenant_id)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    TextInput::make('points')->label('Jumlah Poin')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('description')->label('Keterangan')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        app(AwardCustomerPoints::class)->handle(
                            Auth::user(),
                            (int) $data['customer_id'],
                            (int) $data['points'],
                            $data['description'] ?? null,
                        );

                        Notification::make()->title('Poin pelanggan berhasil ditambahkan.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Penambahan poin gagal.')
                            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                    }
                }),
        ]);
    }
}
