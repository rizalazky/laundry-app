<?php

namespace App\Filament\App\Resources\StockMovements\Schemas;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema->components([
            Section::make('Pencatatan Mutasi Stok')
                ->columns(2)
                ->schema([
                    Select::make('inventory_item_id')
                        ->label('Barang Inventori')
                        ->options(fn (): array => InventoryItem::query()
                            ->where('tenant_id', $tenantId)
                            ->with('baseUnit')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (InventoryItem $item): array => [
                                $item->getKey() => $item->name.' ('.$item->sku.') — '.($item->baseUnit?->name ?? $item->unit),
                            ])
                            ->all())
                        ->searchable()
                        ->live()
                        ->required(),

                    Select::make('movement_type')
                        ->label('Jenis Transaksi')
                        ->options([
                            StockMovementType::Purchase->value => 'Pembelian dari Supplier',
                            StockMovementType::Usage->value => 'Penggunaan Stok',
                            StockMovementType::Adjustment->value => 'Penyesuaian Stok',
                        ])
                        ->native(false)
                        ->live()
                        ->required(),

                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(fn (): array => Supplier::query()
                            ->where('tenant_id', $tenantId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('movement_type') === StockMovementType::Purchase->value)
                        ->required(fn (Get $get): bool => $get('movement_type') === StockMovementType::Purchase->value)
                        ->dehydrated(fn (Get $get): bool => $get('movement_type') === StockMovementType::Purchase->value),

                    TextInput::make('quantity')
                        ->label(fn (Get $get): string => $get('movement_type') === StockMovementType::Purchase->value ? 'Jumlah Pembelian' : 'Jumlah')
                        ->numeric()
                        ->step('0.0001')
                        ->required()
                        ->helperText(fn (Get $get): string => self::quantityHelp($get, $tenantId)),

                    TextInput::make('unit_cost')
                        ->label(fn (Get $get): string => $get('movement_type') === StockMovementType::Purchase->value ? 'Harga per Satuan Pembelian' : 'Biaya per Satuan Dasar')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(fn (Get $get): bool => $get('movement_type') === StockMovementType::Purchase->value)
                        ->helperText('Harga akan dikonversi dan disimpan sebagai biaya per satuan dasar.'),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function quantityHelp(Get $get, ?int $tenantId): string
    {
        if ($get('movement_type') !== StockMovementType::Purchase->value) {
            return $get('movement_type') === StockMovementType::Adjustment->value
                ? 'Masukkan selisih stok. Gunakan angka positif untuk menambah atau negatif untuk mengurangi.'
                : 'Masukkan jumlah dalam satuan dasar barang. Stok keluar tidak dapat membuat saldo menjadi negatif.';
        }

        $item = InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->with('purchaseUnit')
            ->find($get('inventory_item_id'));

        if (! $item || ! $item->purchaseUnit) {
            return 'Pilih barang untuk melihat satuan pembelian dan konversinya.';
        }

        return 'Satuan: '.$item->purchaseUnit->name.'. 1 '.$item->purchaseUnit->name.' = '.$item->purchase_unit_conversion_factor.' '.$item->baseUnit?->name.'.';
    }
}
