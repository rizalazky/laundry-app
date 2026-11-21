<?php

namespace App\Filament\App\Resources\InventoryUnits\Pages;

use App\Filament\App\Resources\InventoryUnits\InventoryUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryUnits extends ListRecords
{
    protected static string $resource = InventoryUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
