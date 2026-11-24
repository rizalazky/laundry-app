<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EntitlementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ActivityLogger $activityLogger;

    public function boot(ActivityLogger $activityLogger): void
    {
        $this->activityLogger = $activityLogger;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        $data['tenant_id'] = $user->tenant_id;
        $data['role'] = $user->role === 'branch_manager'
            ? (in_array($data['role'] ?? null, ['cashier', 'operator', 'courier'], true) ? $data['role'] : 'cashier')
            : (in_array($data['role'] ?? null, ['branch_manager', 'cashier', 'operator', 'courier'], true) ? $data['role'] : 'cashier');

        app(EntitlementService::class)->assertCanCreateUser($user->tenant);

        return $data;
    }

    protected function afterCreate(): void
    {
        $actor = Auth::user();

        if ($actor instanceof User && $this->record instanceof User) {
            $this->activityLogger->record($actor, 'staff.created', $this->record, [
                'name' => $this->record->name,
                'email' => $this->record->email,
                'role' => $this->record->role,
                'is_active' => $this->record->is_active,
            ]);
        }
    }
}
