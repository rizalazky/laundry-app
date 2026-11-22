<?php

namespace App\Filament\App\Resources\BankAccounts\Pages;

use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankAccount extends CreateRecord
{
    protected static string $resource = BankAccountResource::class;
}
