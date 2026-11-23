<?php

namespace App\Filament\App\Resources\Investors\Pages;

use App\Filament\App\Resources\Investors\InvestorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvestors extends ListRecords
{
    protected static string $resource = InvestorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => InvestorResource::canCreate()),
        ];
    }
}
