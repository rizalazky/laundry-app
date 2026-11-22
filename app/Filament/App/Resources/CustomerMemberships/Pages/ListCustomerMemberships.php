<?php

namespace App\Filament\App\Resources\CustomerMemberships\Pages;

use App\Filament\App\Resources\CustomerMemberships\CustomerMembershipResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerMemberships extends ListRecords
{
    protected static string $resource = CustomerMembershipResource::class;
}
