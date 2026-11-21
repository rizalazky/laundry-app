<?php

namespace App\Filament\App\Resources\StockMovements\Schemas;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                        ->required(),

                    Select::make('movement_type')
                        ->label('Jenis Mutasi')
                        ->options(collect(StockMovementType::cases())
                            ->mapWithKeys(fn (StockMovementType $type): array => [$type->value => $type->label()])
                            ->all())
                        ->native(false)
                        ->required(),

                    TextInput::make('quantity')
                        ->label('Jumlah')
                        ->numeric()
                        ->step('0.0001')
                        ->required()
                        ->helperText('Masuk/Keluar: isi jumlah positif. Penyesuaian: gunakan angka positif untuk menambah atau negatif untuk mengurangi.'),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
