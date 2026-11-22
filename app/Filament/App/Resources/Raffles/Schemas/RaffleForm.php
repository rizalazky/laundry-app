<?php

namespace App\Filament\App\Resources\Raffles\Schemas;

use App\Models\Raffle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RaffleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Undian')->columns(2)->schema([
                TextInput::make('name')->label('Nama Undian')->required()->maxLength(255),
                Select::make('status')->label('Status')->options([
                    Raffle::STATUS_DRAFT => 'Draft',
                    Raffle::STATUS_ACTIVE => 'Aktif',
                    Raffle::STATUS_COMPLETED => 'Selesai',
                    Raffle::STATUS_CANCELLED => 'Dibatalkan',
                ])->default(Raffle::STATUS_DRAFT)->required(),
                DateTimePicker::make('starts_at')->label('Mulai Undian')->seconds(false),
                DateTimePicker::make('ends_at')->label('Akhir Undian')->seconds(false)->afterOrEqual('starts_at'),
            ]),
            Section::make('Daftar Hadiah')->schema([
                KeyValue::make('prizes')->label('Hadiah')
                    ->keyLabel('Urutan / Kategori')
                    ->valueLabel('Nama atau Deskripsi Hadiah')
                    ->addActionLabel('Tambah hadiah'),
            ]),
        ]);
    }
}
