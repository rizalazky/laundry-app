<?php

namespace App\Filament\App\Resources\Vouchers\Pages;

use App\Filament\App\Resources\Vouchers\VoucherResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['used_count'] = 0;

        return $data;
    }
}
