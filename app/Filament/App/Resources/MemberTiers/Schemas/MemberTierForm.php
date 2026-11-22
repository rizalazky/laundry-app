<?php

namespace App\Filament\App\Resources\MemberTiers\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class MemberTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Membership')
                ->description('Atur harga pendaftaran, masa berlaku, dan diskon untuk pelanggan yang menjadi member.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Jenis Member')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', Auth::user()?->tenant_id),
                        ),

                    TextInput::make('price')
                        ->label('Harga Pendaftaran')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(9999999999999.99)
                        ->prefix('Rp')
                        ->default(0)
                        ->required(),

                    TextInput::make('duration_days')
                        ->label('Masa Berlaku')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->suffix('hari')
                        ->required(),

                    TextInput::make('discount_percent')
                        ->label('Diskon Member')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(0)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('Jenis Member Aktif')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('Ketentuan Membership')
                ->description('Catat ketentuan tambahan sebagai pasangan kunci dan nilai.')
                ->schema([
                    KeyValue::make('rules')
                        ->label('Ketentuan')
                        ->keyLabel('Ketentuan')
                        ->valueLabel('Keterangan')
                        ->addActionLabel('Tambah ketentuan'),
                ]),
        ]);
    }
}
