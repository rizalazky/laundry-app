<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;

        return $data;
    }
}
