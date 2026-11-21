<?php

namespace App\Filament\App\Resources\InventoryItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class InventoryItemForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema->components([
            Section::make('Informasi Barang Inventori')
                ->columns(2)
                ->schema([
                    TextInput::make('sku')
                        ->label('SKU / Kode Barang')
                        ->required()
                        ->maxLength(100)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                ->where('tenant_id', $tenantId),
                        ),

                    TextInput::make('name')
                        ->label('Nama Barang')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('unit')
                        ->label('Satuan')
                        ->placeholder('Contoh: pcs, kg, liter, ml, roll')
                        ->required()
                        ->maxLength(50),

                    Select::make('item_category')
                        ->label('Kategori Barang')
                        ->options([
                            'raw_material' => 'Bahan Baku',
                            'retail_product' => 'Produk Retail',
                        ])
                        ->required()
                        ->native(false),

                    TextInput::make('min_stock')
                        ->label('Minimum Stok')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99999999.99)
                        ->default(0)
                        ->required()
                        ->helperText('Batas minimum untuk indikator stok rendah pada modul inventori.'),
                ]),
        ]);
    }
}
