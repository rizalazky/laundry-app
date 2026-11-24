<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EntitlementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $tenant = Tenant::query()
                ->whereKey($data['tenant_id'])
                ->lockForUpdate()
                ->firstOrFail();

            app(EntitlementService::class)->assertCanCreateUser($tenant);

            return parent::handleRecordCreation($data);
        });
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
