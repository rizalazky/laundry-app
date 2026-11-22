<?php

namespace App\Filament\App\Resources\EdcMachines\Pages;

use App\Filament\App\Resources\EdcMachines\EdcMachineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdcMachines extends ListRecords
{
    protected static string $resource = EdcMachineResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => EdcMachineResource::canCreate())];
    }
}
