<?php

namespace App\Filament\App\Resources\Raffles\Pages;

use App\Filament\App\Resources\Raffles\RaffleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRaffles extends ListRecords
{
    protected static string $resource = RaffleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
