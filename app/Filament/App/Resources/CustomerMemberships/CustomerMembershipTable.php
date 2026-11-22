<?php

namespace App\Filament\App\Resources\CustomerMemberships;

use App\Actions\Memberships\RegisterCustomerMembership;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CustomerMembershipTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.phone')
                    ->label('WhatsApp')
                    ->placeholder('—'),

                TextColumn::make('memberTier.name')
                    ->label('Jenis Member')
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label('Mulai')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('expired_at')
                    ->label('Berakhir')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CustomerMembership::STATUS_ACTIVE => 'Aktif',
                        CustomerMembership::STATUS_EXPIRED => 'Kedaluwarsa',
                        CustomerMembership::STATUS_CANCELLED => 'Dibatalkan',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        CustomerMembership::STATUS_ACTIVE => 'success',
                        CustomerMembership::STATUS_EXPIRED => 'gray',
                        CustomerMembership::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        CustomerMembership::STATUS_ACTIVE => 'Aktif',
                        CustomerMembership::STATUS_EXPIRED => 'Kedaluwarsa',
                        CustomerMembership::STATUS_CANCELLED => 'Dibatalkan',
                    ]),
            ])
            ->headerActions([
                Action::make('registerMembership')
                    ->label('Daftarkan Member')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => CustomerMembershipResource::canCreate())
                    ->schema([
                        Select::make('customer_id')
                            ->label('Pelanggan')
                            ->options(fn (): array => Customer::query()
                                ->where('tenant_id', Auth::user()?->tenant_id)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),

                        Select::make('member_tier_id')
                            ->label('Jenis Member')
                            ->options(fn (): array => MemberTier::query()
                                ->where('tenant_id', Auth::user()?->tenant_id)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (MemberTier $tier): array => [
                                    $tier->id => $tier->name.' — Rp '.number_format((float) $tier->price, 0, ',', '.').' / '.$tier->duration_days.' hari',
                                ])
                                ->all())
                            ->searchable()
                            ->required(),

                        DateTimePicker::make('started_at')
                            ->label('Tanggal Mulai')
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(RegisterCustomerMembership::class)->handle(
                                Auth::user(),
                                (int) $data['customer_id'],
                                (int) $data['member_tier_id'],
                                isset($data['started_at']) ? \Illuminate\Support\Carbon::parse($data['started_at']) : null,
                            );

                            Notification::make()
                                ->title('Membership pelanggan berhasil didaftarkan.')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Pendaftaran membership gagal.')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('Pendaftaran membership gagal.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
