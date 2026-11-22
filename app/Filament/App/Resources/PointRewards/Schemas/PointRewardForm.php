<?php

namespace App\Filament\App\Resources\PointRewards\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PointRewardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Hadiah')->columns(2)->schema([
                TextInput::make('reward_name')->label('Nama Hadiah')->required()->maxLength(255),
                TextInput::make('points_required')->label('Poin yang Dibutuhkan')->numeric()->integer()->minValue(1)->required(),
                TextInput::make('stock')->label('Stok')->numeric()->minValue(0)
                    ->helperText('Kosongkan jika stok tidak dibatasi.'),
                Toggle::make('is_active')->label('Hadiah Aktif')->default(true)->inline(false),
            ]),
        ]);
    }
}
