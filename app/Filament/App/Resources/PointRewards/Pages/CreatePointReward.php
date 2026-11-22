<?php

namespace App\Filament\App\Resources\PointRewards\Pages;

use App\Filament\App\Resources\PointRewards\PointRewardResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePointReward extends CreateRecord
{
    protected static string $resource = PointRewardResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
