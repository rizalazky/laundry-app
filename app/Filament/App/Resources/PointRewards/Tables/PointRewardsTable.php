<?php

namespace App\Filament\App\Resources\PointRewards\Tables;

use App\Actions\Loyalty\RedeemCustomerReward;
use App\Filament\App\Resources\PointRewards\PointRewardResource;
use App\Models\Customer;
use App\Models\PointReward;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PointRewardsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reward_name')->label('Nama Hadiah')->searchable()->sortable(),
            TextColumn::make('points_required')->label('Poin Dibutuhkan')->numeric()->sortable(),
            TextColumn::make('stock')->label('Stok')->placeholder('Tanpa batas'),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->recordActions([
            Action::make('redeem')
                ->label('Tukarkan')
                ->icon('heroicon-o-gift')
                ->visible(fn (): bool => PointRewardResource::canCreate())
                ->schema([
                    Select::make('customer_id')->label('Pelanggan')
                        ->options(fn (): array => Customer::query()->where('tenant_id', Auth::user()?->tenant_id)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                ])
                ->action(function (PointReward $record, array $data): void {
                    try {
                        app(RedeemCustomerReward::class)->handle(
                            Auth::user(),
                            (int) $data['customer_id'],
                            (int) $record->id,
                        );

                        Notification::make()->title('Penukaran hadiah berhasil.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Penukaran hadiah gagal.')
                            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                    }
                }),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }
}
