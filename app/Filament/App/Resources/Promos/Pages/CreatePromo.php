<?php

namespace App\Filament\App\Resources\Promos\Pages;

use App\Filament\App\Resources\Promos\PromoResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePromo extends CreateRecord
{
    protected static string $resource = PromoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
