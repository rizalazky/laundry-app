<?php

namespace App\Filament\App\Resources\AffiliateReferrals\Tables;

use App\Actions\Affiliates\RegisterAffiliateReferral;
use App\Models\AffiliateReferral;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
