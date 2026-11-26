<?php

namespace App\\Filament\\Resources\\Plans\\Schemas;

use Filament\\Forms\\Components\\Repeater;
use Filament\\Forms\\Components\\TextInput;
use Filament\\Forms\\Components\\Textarea;
use Filament\\Forms\\Components\\Toggle;
use Filament\\Schemas\\Components\\Section;
use Filament\\Schemas\\Schema;
use Illuminate\\Validation\\Rules\\Unique;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Paket')
                ->description('Atur identitas paket yang akan ditampilkan pada halaman Billing milik Owner.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Paket')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('code')
                        ->label('Kode Paket')
                        ->required()
                        ->alphaDash()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true),

                    Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(3)
                        ->columnSpanFull(),

                    TextInput::make('sort_order')
                        ->label('Urutan Tampilan')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('Tampilkan pada katalog Owner')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('Harga Langganan')
                ->description('Harga disimpan sebagai snapshot pada invoice saat invoice dibuat. Gunakan 0 untuk paket gratis.')
                ->columns(2)
                ->schema([
                    TextInput::make('monthly_price')
                        ->label('Harga Bulanan')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(9999999999999.99)
                        ->prefix('Rp')
                        ->default(0)
                        ->required(),

                    TextInput::make('yearly_price')
                        ->label('Harga Tahunan')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(9999999999999.99)
                        ->prefix('Rp')
                        ->nullable(),

                    TextInput::make('currency')
                        ->label('Mata Uang')
                        ->required()
                        ->default('IDR')
                        ->length(3)
                        ->dehydrateStateUsing(fn ($state): string => strtoupper($state)),
                ]),

            Section::make('Fitur & Batas Penggunaan')
                ->description('Gunakan kode fitur yang dibaca EntitlementService, misalnya max_outlets, max_users, atau kode fitur boolean. Nilai dapat berupa true, false, angka, atau unlimited.')
                ->schema([
                    Repeater::make('features')
                        ->relationship()
                        ->label('Daftar fitur paket')
                        ->schema([
                            TextInput::make('feature_code')
                                ->label('Kode Fitur')
                                ->required()
                                ->alphaDash()
                                ->maxLength(100),

                            TextInput::make('value')
                                ->label('Nilai')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Tambah fitur atau limit')
                        ->reorderable(false)
                        ->collapsible(),
                ]),
        ]);
    }
}
