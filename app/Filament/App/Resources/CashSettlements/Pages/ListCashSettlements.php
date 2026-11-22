<?php

namespace App\Filament\App\Resources\CashSettlements\Pages;

use App\Filament\App\Resources\CashSettlements\CashSettlementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashSettlements extends ListRecords
{
    protected static string $resource = CashSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => CashSettlementResource::canCreate())];
    }
}
