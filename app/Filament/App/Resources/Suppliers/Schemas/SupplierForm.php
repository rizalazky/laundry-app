<?php

namespace App\Filament\App\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Supplier')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Supplier')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label('Nomor Telepon / WhatsApp')
                        ->tel()
                        ->maxLength(30),

                    Textarea::make('address')
                        ->label('Alamat')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
