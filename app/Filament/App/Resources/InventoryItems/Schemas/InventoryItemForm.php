<?php

namespace App\Filament\App\Resources\InventoryItems\Schemas;

use App\Models\InventoryUnit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

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

                    Select::make('base_unit_id')
                        ->label('Satuan Dasar Stok')
                        ->options(fn (Get $get): array => self::unitOptions($tenantId, $get('base_unit_id')))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->rules([
                            Rule::exists('inventory_units', 'id')
                                ->where('tenant_id', $tenantId)
                                ->whereNull('deleted_at'),
                        ])
                        ->helperText('Semua saldo stok barang akan disimpan menggunakan satuan ini.'),

                    Select::make('purchase_unit_id')
                        ->label('Satuan Pembelian')
                        ->options(fn (Get $get): array => self::unitOptions($tenantId, $get('purchase_unit_id')))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->rules([
                            Rule::exists('inventory_units', 'id')
                                ->where('tenant_id', $tenantId)
                                ->whereNull('deleted_at'),
                        ])
                        ->helperText('Satuan yang digunakan saat barang dibeli dari supplier.'),

                    TextInput::make('purchase_unit_conversion_factor')
                        ->label('Konversi ke Satuan Dasar')
                        ->numeric()
                        ->minValue(0.000001)
                        ->maxValue(999999999999.999999)
                        ->default(1)
                        ->required()
                        ->helperText('Contoh: 1 jerigen = 20 liter, isi 20.'),

                    Select::make('item_category')
                        ->label('Kategori Barang')
                        ->options([
                            'raw_material' => 'Bahan Baku',
                            'retail_product' => 'Produk Retail',
                        ])
                        ->required()
                        ->native(false),

                    TextInput::make('min_stock')
                        ->label('Minimum Stok (satuan dasar)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99999999.99)
                        ->default(0)
                        ->required()
                        ->helperText('Batas minimum untuk indikator stok rendah, dihitung dalam satuan dasar.'),
                ]),
        ]);
    }

    public static function unitOptions(?int $tenantId, mixed $selectedUnitId): array
    {
        if ($tenantId === null) {
            return [];
        }

        return InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($selectedUnitId): void {
                $query->where('is_active', true);

                if (filled($selectedUnitId)) {
                    $query->orWhereKey($selectedUnitId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active'])
            ->mapWithKeys(fn (InventoryUnit $unit): array => [
                $unit->id => $unit->name.' ('.$unit->code.')'.($unit->is_active ? '' : ' (Nonaktif)'),
            ])
            ->all();
    }
}
