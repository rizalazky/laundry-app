<?php

namespace App\Filament\App\Resources\StockTransfers\Schemas;

use App\Models\Branch;
use App\Models\InventoryItem;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class StockTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema->components([
            Section::make('Informasi Distribusi')
                ->columns(2)
                ->schema([
                    Select::make('transfer_type')
                        ->label('Jenis')
                        ->options(['distribution' => 'Distribusi Stok', 'return' => 'Pengembalian Stok'])
                        ->default('distribution')
                        ->required(),

                    Select::make('source_branch_id')
                        ->label('Lokasi Asal')
                        ->options(fn (): array => Branch::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),

                    Select::make('destination_branch_id')
                        ->label('Lokasi Tujuan')
                        ->options(fn (): array => Branch::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->different('source_branch_id'),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Barang yang Dikirim')
                ->schema([
                    Repeater::make('items')
                        ->label('Daftar Barang')
                        ->minItems(1)
                        ->columns(2)
                        ->schema([
                            Select::make('inventory_item_id')
                                ->label('Barang')
                                ->options(fn (): array => InventoryItem::query()->where('tenant_id', $tenantId)->with('baseUnit')->orderBy('name')->get()->mapWithKeys(fn (InventoryItem $item): array => [$item->id => $item->name.' ('.$item->sku.') — '.($item->baseUnit?->code ?? $item->unit)])->all())
                                ->searchable()
                                ->required(),

                            TextInput::make('quantity')
                                ->label('Jumlah')
                                ->numeric()
                                ->minValue(0.0001)
                                ->step(0.0001)
                                ->required(),

                            TextInput::make('unit_cost')
                                ->label('Biaya per Satuan (opsional)')
                                ->numeric()
                                ->minValue(0)
                                ->default(0),
                        ]),
                ]),
        ]);
    }
}
