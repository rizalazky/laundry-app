<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $tenant = static::getModel()::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'owner_email' => $data['owner_email'],
                'phone' => $data['phone'] ?? null,
                'plan' => $data['plan'],
                'max_branches' => $data['max_branches'],
                'subscription_ends_at' => $data['subscription_ends_at'],
                'is_active' => $data['is_active'],
            ]);

            $branch = $tenant->branches()->create([
                'name' => $data['initial_branch_name'],
                'code' => strtoupper($data['initial_branch_code']),
                'is_active' => true,
            ]);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
                'role' => 'tenant_owner',
                'is_active' => true,
            ]);

            $owner->branches()->attach($branch->id);

            return $tenant;
        });
    }
}
