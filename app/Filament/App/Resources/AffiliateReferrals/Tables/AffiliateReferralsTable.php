<?php

namespace App\Filament\App\Resources\AffiliateReferrals\Tables;

use App\Actions\Affiliates\RegisterAffiliateReferral;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AffiliateReferralsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('referred.name')->label('Tenant Referral')->searchable()->sortable(),
            TextColumn::make('commission_rate')->label('Komisi')->suffix('%')->sortable(),
            TextColumn::make('referred_at')->label('Tanggal Referral')->dateTime('d M Y H:i')->sortable(),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    AffiliateReferral::STATUS_APPROVED => 'Disetujui',
                    AffiliateReferral::STATUS_REJECTED => 'Ditolak',
                    default => 'Menunggu',
                }),
            TextColumn::make('payouts_sum_amount')->label('Total Payout')
                ->sum('payouts', 'amount')->money('IDR'),
            TextColumn::make('payouts_paid_sum_amount')->label('Sudah Dibayar')
                ->sum('payouts', 'amount', fn ($query) => $query->where('status', AffiliatePayout::STATUS_PAID))->money('IDR'),
            TextColumn::make('payouts_pending_sum_amount')->label('Menunggu Pembayaran')
                ->sum('payouts', 'amount', fn ($query) => $query->where('status', AffiliatePayout::STATUS_PENDING))->money('IDR'),
        ])->filters([
            SelectFilter::make('status')->options([
                AffiliateReferral::STATUS_PENDING => 'Menunggu',
                AffiliateReferral::STATUS_APPROVED => 'Disetujui',
                AffiliateReferral::STATUS_REJECTED => 'Ditolak',
            ]),
        ])->recordActions([
            Action::make('approve')
                ->label('Setujui')
                ->color('success')
                ->visible(fn (AffiliateReferral $record): bool => $record->status === AffiliateReferral::STATUS_PENDING
                    && (Auth::user()?->hasPermissionTo('affiliates.manage') ?? false))
                ->action(fn (AffiliateReferral $record) => $record->update(['status' => AffiliateReferral::STATUS_APPROVED])),
            Action::make('reject')
                ->label('Tolak')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (AffiliateReferral $record): bool => $record->status === AffiliateReferral::STATUS_PENDING
                    && (Auth::user()?->hasPermissionTo('affiliates.manage') ?? false))
                ->action(fn (AffiliateReferral $record) => $record->update(['status' => AffiliateReferral::STATUS_REJECTED])),
            Action::make('markPayoutPaid')
                ->label('Tandai Dibayar')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn (AffiliateReferral $record): bool => $record->status === AffiliateReferral::STATUS_APPROVED
                    && $record->payouts()->where('status', AffiliatePayout::STATUS_PENDING)->exists()
                    && (Auth::user()?->hasPermissionTo('affiliates.manage') ?? false))
                ->schema([
                    Select::make('payout_id')->label('Payout')
                        ->options(fn (AffiliateReferral $record): array => $record->payouts()
                            ->where('status', AffiliatePayout::STATUS_PENDING)
                            ->orderBy('payout_date')->get()
                            ->mapWithKeys(fn (AffiliatePayout $payout): array => [$payout->id => 'Rp '.number_format((float) $payout->amount, 2, ',', '.').' — '.$payout->payout_date->format('d/m/Y')])
                            ->all())->required(),
                ])
                ->action(function (AffiliateReferral $record, array $data): void {
                    app(UpdateAffiliatePayoutStatus::class)->handle(Auth::user(), (int) $data['payout_id'], AffiliatePayout::STATUS_PAID);
                    Notification::make()->title('Payout ditandai sudah dibayar.')->success()->send();
                }),
            Action::make('cancelPayout')
                ->label('Batalkan Payout')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (AffiliateReferral $record): bool => $record->payouts()->where('status', AffiliatePayout::STATUS_PENDING)->exists()
                    && (Auth::user()?->hasPermissionTo('affiliates.manage') ?? false))
                ->schema([
                    Select::make('payout_id')->label('Payout')
                        ->options(fn (AffiliateReferral $record): array => $record->payouts()
                            ->where('status', AffiliatePayout::STATUS_PENDING)
                            ->orderBy('payout_date')->get()
                            ->mapWithKeys(fn (AffiliatePayout $payout): array => [$payout->id => 'Rp '.number_format((float) $payout->amount, 2, ',', '.').' — '.$payout->payout_date->format('d/m/Y')])
                            ->all())->required(),
                ])
                ->action(function (AffiliateReferral $record, array $data): void {
                    app(UpdateAffiliatePayoutStatus::class)->handle(Auth::user(), (int) $data['payout_id'], AffiliatePayout::STATUS_CANCELLED);
                    Notification::make()->title('Payout berhasil dibatalkan.')->success()->send();
                }),
            Action::make('recordPayout')
                ->label('Catat Payout')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (AffiliateReferral $record): bool => $record->status === AffiliateReferral::STATUS_APPROVED
                    && (Auth::user()?->hasPermissionTo('affiliates.manage') ?? false))
                ->schema([
                    TextInput::make('amount')->label('Nominal (Rp)')->numeric()->minValue(1)->required(),
                    DatePicker::make('payout_date')->label('Tanggal Payout')->default(now())->required(),
                    TextInput::make('reference')->label('Referensi')->maxLength(255),
                    Textarea::make('notes')->label('Catatan'),
                ])
                ->action(function (AffiliateReferral $record, array $data): void {
                    $record->payouts()->create([
                        'tenant_id' => $record->referrer_tenant_id,
                        'amount' => $data['amount'],
                        'payout_date' => $data['payout_date'],
                        'status' => AffiliatePayout::STATUS_PENDING,
                        'reference' => $data['reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]);

                    Notification::make()->title('Payout berhasil dicatat sebagai menunggu pembayaran.')->success()->send();
                }),
        ])->headerActions([
            Action::make('registerReferral')
                ->label('Tambah Referral')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => Auth::user()?->hasPermissionTo('affiliates.manage') ?? false)
                ->schema([
                    Select::make('referred_tenant_id')->label('Tenant yang Direferensikan')
                        ->options(fn (): array => Tenant::query()
                            ->where('id', '!=', Auth::user()?->tenant_id)
                            ->where('is_active', true)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    TextInput::make('commission_rate')->label('Komisi (%)')
                        ->numeric()->minValue(0)->maxValue(100)->default(10)->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RegisterAffiliateReferral::class)->handle(
                            (int) Auth::user()->tenant_id,
                            (int) $data['referred_tenant_id'],
                            (float) $data['commission_rate'],
                        );

                        Notification::make()->title('Referral berhasil didaftarkan.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Referral gagal didaftarkan.')
                            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                    }
                }),
        ]);
    }
}
