<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;
}
