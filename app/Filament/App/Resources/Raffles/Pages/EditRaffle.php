<?php

namespace App\Filament\App\Resources\Raffles\Pages;

use App\Filament\App\Resources\Raffles\RaffleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRaffle extends EditRecord
{
    protected static string $resource = RaffleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (): bool => RaffleResource::canDelete($this->getRecord())),
        ];
    }
}
