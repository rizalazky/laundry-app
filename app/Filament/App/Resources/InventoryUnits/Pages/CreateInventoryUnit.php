<?php

namespace App\Filament\App\Resources\InventoryUnits\Pages;

use App\Filament\App\Resources\InventoryUnits\InventoryUnitResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateInventoryUnit extends CreateRecord
{
    protected static string $resource = InventoryUnitResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
