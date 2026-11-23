<?php

namespace App\Filament\App\Resources\CustomerMemberships;

use App\Actions\Memberships\RegisterCustomerMembership;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

                TextColumn::make('activatedBy.name')
                    ->label('Diaktifkan oleh')
                    ->placeholder('—'),

                TextColumn::make('activatedAtBranch.name')
                    ->label('Outlet aktivasi')
                    ->placeholder('—'),

                TextColumn::make('activated_at')
                    ->label('Waktu aktivasi')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, CustomerMembership $record): string => $state === CustomerMembership::STATUS_ACTIVE && $record->expired_at?->isPast()
                        ? 'Kedaluwarsa'
                        : match ($state) {
                            CustomerMembership::STATUS_ACTIVE => 'Aktif',
                            CustomerMembership::STATUS_EXPIRED => 'Kedaluwarsa',
                            CustomerMembership::STATUS_CANCELLED => 'Dibatalkan',
                            CustomerMembership::STATUS_REPLACED => 'Digantikan',
                            default => ucfirst($state),
                        })
                    ->color(fn (string $state, CustomerMembership $record): string => $state === CustomerMembership::STATUS_ACTIVE && $record->expired_at?->isPast()
                        ? 'gray'
                        : match ($state) {
                            CustomerMembership::STATUS_ACTIVE => 'success',
                            CustomerMembership::STATUS_EXPIRED, CustomerMembership::STATUS_REPLACED => 'gray',
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
                        CustomerMembership::STATUS_REPLACED => 'Digantikan',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        CustomerMembership::STATUS_ACTIVE => $query
                            ->where('status', CustomerMembership::STATUS_ACTIVE)
                            ->where('started_at', '<=', now())
                            ->where('expired_at', '>', now()),
                        CustomerMembership::STATUS_EXPIRED => $query->where(function (Builder $expired): void {
                            $expired->where('status', CustomerMembership::STATUS_EXPIRED)
                                ->orWhere(function (Builder $stale): void {
                                    $stale->where('status', CustomerMembership::STATUS_ACTIVE)
                                        ->where('expired_at', '<=', now());
                                });
                        }),
                        CustomerMembership::STATUS_CANCELLED => $query->where('status', CustomerMembership::STATUS_CANCELLED),
                        CustomerMembership::STATUS_REPLACED => $query->where('status', CustomerMembership::STATUS_REPLACED),
                        default => $query,
                    }),
            ])
            ->headerActions([
                Action::make('registerMembership')
                    ->label('Aktifkan Membership')
                    ->icon('heroicon-o-check-badge')
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
                            ->live()
                            ->required(),

                        Placeholder::make('active_membership_warning')
                            ->label('Membership saat ini')
                            ->content(function (Get $get): string {
                                $customerId = $get('customer_id');

                                if (! $customerId) {
                                    return 'Pilih pelanggan untuk memeriksa membership aktif.';
                                }

                                $membership = CustomerMembership::query()
                                    ->with('memberTier')
                                    ->where('tenant_id', Auth::user()?->tenant_id)
                                    ->where('customer_id', $customerId)
                                    ->where('status', CustomerMembership::STATUS_ACTIVE)
                                    ->where('started_at', '<=', now())
                                    ->where('expired_at', '>', now())
                                    ->first();

                                if (! $membership) {
                                    return 'Pelanggan tidak memiliki membership aktif. Membership baru akan diaktifkan setelah pembayaran dikonfirmasi.';
                                }

                                $newTier = MemberTier::query()
                                    ->where('tenant_id', Auth::user()?->tenant_id)
                                    ->find($get('member_tier_id'));

                                $oldTierName = $membership->memberTier?->name ?? 'membership aktif';
                                $newTierName = $newTier?->name ?? 'tier baru';

                                return "Customer ini masih memiliki membership {$oldTierName}. Jika dilanjutkan, membership {$oldTierName} akan digantikan dengan {$newTierName}.";
                            }),

                        Select::make('member_tier_id')
                            ->label('Tier Membership Baru')
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
                            ->live()
                            ->required(),

                        DateTimePicker::make('started_at')
                            ->label('Tanggal Mulai')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),

                        Checkbox::make('payment_confirmed')
                            ->label('Saya sudah menerima dan memverifikasi pembayaran membership di luar aplikasi.')
                            ->accepted()
                            ->required(),

                        Checkbox::make('confirm_replacement')
                            ->label('Saya setuju mengganti membership aktif pelanggan dengan tier baru.')
                            ->visible(function (Get $get): bool {
                                $customerId = $get('customer_id');

                                return filled($customerId) && CustomerMembership::query()
                                    ->where('tenant_id', Auth::user()?->tenant_id)
                                    ->where('customer_id', $customerId)
                                    ->where('status', CustomerMembership::STATUS_ACTIVE)
                                    ->where('started_at', '<=', now())
                                    ->where('expired_at', '>', now())
                                    ->exists();
                            })
                            ->required(function (Get $get): bool {
                                $customerId = $get('customer_id');

                                return filled($customerId) && CustomerMembership::query()
                                    ->where('tenant_id', Auth::user()?->tenant_id)
                                    ->where('customer_id', $customerId)
                                    ->where('status', CustomerMembership::STATUS_ACTIVE)
                                    ->where('started_at', '<=', now())
                                    ->where('expired_at', '>', now())
                                    ->exists();
                            }),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi Aktivasi Membership')
                    ->modalDescription('Pastikan pembayaran eksternal sudah diterima. Jika pelanggan masih memiliki membership aktif, membership lama hanya akan digantikan setelah aktivasi tier baru berhasil.')
                    ->modalSubmitActionLabel('Ya, Aktifkan Membership')
                    ->modalCancelActionLabel('Batalkan')
                    ->action(function (array $data): void {
                        $branch = Filament::getTenant();

                        if (! $branch instanceof Branch) {
                            Notification::make()
                                ->title('Cabang aktif tidak ditemukan.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            app(RegisterCustomerMembership::class)->handle(
                                Auth::user(),
                                $branch,
                                (int) $data['customer_id'],
                                (int) $data['member_tier_id'],
                                isset($data['started_at']) ? \Illuminate\Support\Carbon::parse($data['started_at']) : null,
                                (bool) ($data['payment_confirmed'] ?? false),
                                (bool) ($data['confirm_replacement'] ?? false),
                            );

                            Notification::make()
                                ->title('Membership berhasil diaktifkan.')
                                ->body('Status membership dan riwayat aktivasi telah diperbarui.')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Aktivasi membership gagal.')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title('Aktivasi membership gagal.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
