<?php

namespace App\Filament\App\Resources\ProfitSharings;

use App\Filament\App\Resources\ProfitSharings\Pages\ListProfitSharings;
use App\Models\ProfitSharing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ProfitSharingResource extends Resource
{
    protected static ?string $model = ProfitSharing::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 21;
    protected static ?string $navigationLabel = 'Bagi Hasil Investor';
    protected static ?string $modelLabel = 'Bagi Hasil';
    protected static ?string $pluralModelLabel = 'Bagi Hasil Investor';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->is_active && $user->role === 'tenant_owner'
            && $user->tenant_id !== null && $user->hasPermissionTo('investors.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->with(['investor.branch', 'journal'])
            ->orderByDesc('period_month')
            ->orderBy('id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('period_month')->label('Periode')->sortable(),
            TextColumn::make('investor.name')->label('Investor')->searchable(),
            TextColumn::make('investor.branch.name')->label('Cabang'),
            TextColumn::make('net_profit_base')->label('Laba Bersih Snapshot')->money('IDR'),
            TextColumn::make('share_percent_snapshot')->label('Persentase')->suffix('%'),
            TextColumn::make('payout_amount')->label('Hak Investor')->money('IDR')->sortable(),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state): string => $state === ProfitSharing::STATUS_PAID ? 'Dibayar' : 'Draft')
                ->color(fn (string $state): string => $state === ProfitSharing::STATUS_PAID ? 'success' : 'warning'),
            TextColumn::make('paid_at')->label('Dibayar Pada')->dateTime('d M Y H:i')->placeholder('—'),
        ])->recordActions([
            \Filament\Actions\Action::make('markPaid')
                ->label('Bayar')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (ProfitSharing $record): bool => $record->status === ProfitSharing::STATUS_DRAFT
                    && Auth::user()?->role === 'tenant_owner'
                    && (Auth::user()?->hasPermissionTo('investors.manage') ?? false))
                ->form([
                    \Filament\Forms\Components\Select::make('payment_account_code')
                        ->label('Bayar Dari')
                        ->options(['1000' => 'Kas', '1010' => 'Bank'])
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (ProfitSharing $record, array $data): void {
                    app(\App\Actions\Investors\MarkProfitSharingPaid::class)->handle(
                        (int) Auth::user()->tenant_id,
                        (int) $record->id,
                        $data['payment_account_code'],
                    );

                    \Filament\Notifications\Notification::make()
                        ->title('Bagi hasil berhasil dibayar')
                        ->success()
                        ->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProfitSharings::route('/')];
    }
}
