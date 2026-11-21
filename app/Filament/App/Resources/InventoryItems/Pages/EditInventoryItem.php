<?php

namespace App\Filament\App\Resources\InventoryItems\Pages;

use App\Filament\App\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryUnit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditInventoryItem extends EditRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $tenantId = Auth::user()->tenant_id;

        $baseUnit = InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->find($data['base_unit_id'] ?? null);

        $purchaseUnit = InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->find($data['purchase_unit_id'] ?? null);

        if (! $baseUnit || ! $purchaseUnit) {
            throw ValidationException::withMessages([
                'data.base_unit_id' => 'Pilih satuan dari tenant ini.',
                'data.purchase_unit_id' => 'Pilih satuan pembelian dari tenant ini.',
            ]);
        }

        if (! $baseUnit->is_active && (int) $baseUnit->id !== (int) $this->record->base_unit_id) {
            throw ValidationException::withMessages([
                'data.base_unit_id' => 'Satuan nonaktif hanya dapat dipertahankan pada barang yang sudah menggunakannya.',
            ]);
        }

        if (! $purchaseUnit->is_active && (int) $purchaseUnit->id !== (int) $this->record->purchase_unit_id) {
            throw ValidationException::withMessages([
                'data.purchase_unit_id' => 'Satuan nonaktif hanya dapat dipertahankan pada barang yang sudah menggunakannya.',
            ]);
        }

        $data['unit'] = $baseUnit->name;

        return $data;
    }
}
