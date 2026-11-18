<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        $data['tenant_id'] = $user->tenant_id;
        $data['role'] = $user->role === 'branch_manager'
            ? (in_array($data['role'] ?? null, ['cashier', 'operator'], true) ? $data['role'] : 'cashier')
            : (in_array($data['role'] ?? null, ['branch_manager', 'cashier', 'operator'], true) ? $data['role'] : 'cashier');

        return $data;
    }
}
