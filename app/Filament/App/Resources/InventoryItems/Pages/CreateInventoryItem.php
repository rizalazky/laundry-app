<?php

namespace App\Filament\App\Resources\InventoryItems\Pages;

use App\Filament\App\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryUnit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenantId = Auth::user()->tenant_id;

        $baseUnit = InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($data['base_unit_id'] ?? null);

        $purchaseUnit = InventoryUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($data['purchase_unit_id'] ?? null);

        if (! $baseUnit || ! $purchaseUnit) {
            throw ValidationException::withMessages([
                'data.base_unit_id' => 'Pilih satuan aktif dari tenant ini.',
                'data.purchase_unit_id' => 'Pilih satuan pembelian aktif dari tenant ini.',
            ]);
        }

        $data['tenant_id'] = $tenantId;
        $data['unit'] = $baseUnit->name;

        return $data;
    }
}
