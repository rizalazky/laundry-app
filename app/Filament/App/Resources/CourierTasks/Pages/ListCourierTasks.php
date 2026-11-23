<?php

namespace App\Filament\App\Resources\CourierTasks\Pages;

use App\Filament\App\Resources\CourierTasks\CourierTaskResource;
use Filament\Resources\Pages\ListRecords;

class ListCourierTasks extends ListRecords
{
    protected static string $resource = CourierTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
