<?php

namespace App\Filament\App\Resources\MemberTiers\Pages;

use App\Filament\App\Resources\MemberTiers\MemberTierResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateMemberTier extends CreateRecord
{
    protected static string $resource = MemberTierResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
