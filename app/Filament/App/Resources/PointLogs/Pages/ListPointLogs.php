<?php

namespace App\Filament\App\Resources\PointLogs\Pages;

use App\Filament\App\Resources\PointLogs\PointLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPointLogs extends ListRecords
{
    protected static string $resource = PointLogResource::class;
}
