<?php

namespace App\Filament\App\Resources\Promos\Pages;

use App\Filament\App\Resources\Promos\PromoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromo extends EditRecord
{
    protected static string $resource = PromoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (): bool => PromoResource::canDelete($this->getRecord())),
        ];
    }
}
