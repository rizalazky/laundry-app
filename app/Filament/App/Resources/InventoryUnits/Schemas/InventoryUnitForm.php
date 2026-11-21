<?php

namespace App\Filament\App\Resources\InventoryUnits\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class InventoryUnitForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema->components([
            Section::make('Informasi Satuan')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Kode Satuan')
                        ->placeholder('Contoh: pcs, kg, liter, ml')
                        ->required()
                        ->maxLength(50)
                        ->alphaDash()
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                ->where('tenant_id', $tenantId),
                        ),

                    TextInput::make('name')
                        ->label('Nama Satuan')
                        ->placeholder('Contoh: Pieces, Kilogram, Liter')
                        ->required()
                        ->maxLength(100),

                    TextInput::make('decimal_places')
                        ->label('Jumlah Angka Desimal')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(6)
                        ->default(4)
                        ->required()
                        ->helperText('Contoh: 0 untuk pcs, 2 untuk kg, 3 untuk liter.'),

                    Toggle::make('is_active')
                        ->label('Satuan Aktif')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Satuan nonaktif tidak tersedia untuk barang baru. Barang lama tetap dapat menggunakannya.'),
                ]),
        ]);
    }
}
