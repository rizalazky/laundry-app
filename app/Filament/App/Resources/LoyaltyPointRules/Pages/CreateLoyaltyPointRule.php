<?php

namespace App\Filament\App\Resources\LoyaltyPointRules\Pages;

use App\Filament\App\Resources\LoyaltyPointRules\LoyaltyPointRuleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateLoyaltyPointRule extends CreateRecord
{
    protected static string $resource = LoyaltyPointRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
