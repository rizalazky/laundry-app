<?php

namespace App\Filament\App\Resources\MassInvoiceSchedules\Pages;

use App\Filament\App\Resources\MassInvoiceSchedules\MassInvoiceScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMassInvoiceSchedules extends ListRecords
{
    protected static string $resource = MassInvoiceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat Jadwal Faktur'),
        ];
    }
}
