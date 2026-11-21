<?php

namespace App\Filament\App\Resources\InventoryUnits\Pages;

use App\Filament\App\Resources\InventoryUnits\InventoryUnitResource;
use App\Models\InventoryItem;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditInventoryUnit extends EditRecord
{
    protected static string $resource = InventoryUnitResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->update($data);

            InventoryItem::withTrashed()
                ->where('tenant_id', $record->tenant_id)
                ->where('base_unit_id', $record->getKey())
                ->update(['unit' => $record->name]);

            return $record->refresh();
        });
    }
}
