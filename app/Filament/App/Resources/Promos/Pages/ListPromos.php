<?php

namespace App\Filament\App\Resources\Promos\Pages;

use App\Filament\App\Resources\Promos\PromoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromos extends ListRecords
{
    protected static string $resource = PromoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
