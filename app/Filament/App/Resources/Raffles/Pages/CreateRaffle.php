<?php

namespace App\Filament\App\Resources\Raffles\Pages;

use App\Filament\App\Resources\Raffles\RaffleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateRaffle extends CreateRecord
{
    protected static string $resource = RaffleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
