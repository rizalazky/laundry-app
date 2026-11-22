<?php

namespace App\Filament\App\Resources\Vouchers\Schemas;

use App\Models\Promo;
use App\Models\Voucher;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class VoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Voucher')->columns(2)->schema([
                TextInput::make('code')->label('Kode Voucher')->required()->maxLength(60)
                    ->alphaDash()->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', Auth::user()?->tenant_id)),
                Select::make('voucher_type')->label('Jenis Voucher')->options([
                    Voucher::TYPE_GENERAL => 'Umum',
                    Voucher::TYPE_REFERRAL => 'Referral',
                ])->required(),
                Select::make('promo_id')->label('Promo Terkait')->options(fn (): array => Promo::query()
                    ->where('tenant_id', Auth::user()?->tenant_id)
                    ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->preload()->placeholder('Tanpa promo'),
                TextInput::make('quota')->label('Kuota Penggunaan')->numeric()->integer()->minValue(1)
                    ->helperText('Kosongkan jika kuota tidak dibatasi.'),
                DateTimePicker::make('starts_at')->label('Mulai Berlaku')->seconds(false),
                DateTimePicker::make('expires_at')->label('Akhir Berlaku')->seconds(false)->afterOrEqual('starts_at'),
                Toggle::make('is_active')->label('Voucher Aktif')->default(true)->inline(false),
            ]),
        ]);
    }
}
