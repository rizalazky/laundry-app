<?php

namespace App\Filament\App\Resources\LoyaltyPointRules\Schemas;

use App\Models\Branch;
use App\Models\LoyaltyPointRule;
use App\Models\MemberTier;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class LoyaltyPointRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema->components([
            Section::make('Program Poin')->description('Satu program berlaku untuk satu outlet, target pelanggan, dan periode tertentu.')
                ->columns(2)->schema([
                    TextInput::make('name')->label('Nama Program')->required()->maxLength(255),
                    Select::make('branch_id')->label('Outlet')
                        ->options(fn (): array => Branch::query()->where('tenant_id', $tenantId)
                            ->where('is_workshop', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    Select::make('target_type')->label('Target Pelanggan')->options([
                        LoyaltyPointRule::TARGET_ALL_CUSTOMERS => 'Semua Konsumen',
                        LoyaltyPointRule::TARGET_ALL_MEMBERS => 'Semua Member',
                        LoyaltyPointRule::TARGET_MEMBER_TIER => 'Member Tertentu',
                    ])->live()->required(),
                    Select::make('member_tier_id')->label('Jenis Member')
                        ->options(fn (): array => MemberTier::query()->where('tenant_id', $tenantId)
                            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(fn ($get): bool => $get('target_type') === LoyaltyPointRule::TARGET_MEMBER_TIER)
                        ->visible(fn ($get): bool => $get('target_type') === LoyaltyPointRule::TARGET_MEMBER_TIER),
                    TextInput::make('amount_step')->label('Setiap Transaksi Kelipatan')->numeric()->prefix('Rp')
                        ->minValue(1)->maxValue(9999999999999.99)->required()
                        ->helperText('Poin dihitung dari total transaksi setelah diskon. Pecahan kelipatan tidak dihitung.'),
                    TextInput::make('points_per_step')->label('Poin per Kelipatan')->numeric()->integer()
                        ->minValue(1)->maxValue(1000000000)->required(),
                    DateTimePicker::make('starts_at')->label('Mulai Program')->seconds(false)->required(),
                    DateTimePicker::make('ends_at')->label('Akhir Program')->seconds(false)->after('starts_at')->required(),
                    Toggle::make('is_active')->label('Program Aktif')->default(true)->inline(false),
                ]),
        ]);
    }
}
