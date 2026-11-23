<?php

namespace App\Filament\App\Resources\LoyaltyPointRules\Pages;

use App\Filament\App\Resources\LoyaltyPointRules\LoyaltyPointRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLoyaltyPointRules extends ListRecords
{
    protected static string $resource = LoyaltyPointRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
