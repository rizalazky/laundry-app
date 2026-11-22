<?php

namespace App\Filament\App\Resources\Promos\Schemas;

use App\Models\Promo;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Promo')->columns(2)->schema([
                TextInput::make('name')->label('Nama Promo')->required()->maxLength(255),
                Select::make('category')->label('Kategori')->options([
                    Promo::CATEGORY_BASIC => 'Basic',
                    Promo::CATEGORY_GENERAL => 'General',
                ])->required(),
                Select::make('reward_type')->label('Jenis Benefit')->options([
                    Promo::REWARD_PERCENT => 'Diskon Persentase',
                    Promo::REWARD_FIXED => 'Potongan Nominal',
                ])->required()->live(),
                TextInput::make('reward_value')->label('Nilai Benefit')->numeric()->minValue(0)->required()
                    ->suffix(fn ($get): string => $get('reward_type') === Promo::REWARD_PERCENT ? '%' : 'Rp'),
                DateTimePicker::make('start_at')->label('Mulai Promo')->seconds(false),
                DateTimePicker::make('end_at')->label('Akhir Promo')->seconds(false)->afterOrEqual('start_at'),
                Toggle::make('is_active')->label('Promo Aktif')->default(true)->inline(false),
            ]),
            Section::make('Ketentuan')->schema([
                KeyValue::make('rules')->label('Ketentuan Promo')
                    ->keyLabel('Ketentuan')->valueLabel('Nilai')
                    ->addActionLabel('Tambah ketentuan'),
            ]),
        ]);
    }
}
