<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ActivityLogger $activityLogger;

    /**
     * @var array<string, mixed>
     */
    protected array $originalStaffSnapshot = [];

    public function boot(ActivityLogger $activityLogger): void
    {
        $this->activityLogger = $activityLogger;
    }

    protected function beforeSave(): void
    {
        $this->originalStaffSnapshot = $this->record->only([
            'name',
            'email',
            'phone_whatsapp',
            'role',
            'is_active',
            'monthly_base_salary',
        ]);
    }

    protected function afterSave(): void
    {
        $actor = Auth::user();
        $staff = $this->record->fresh();

        if (! $actor instanceof User || ! $staff instanceof User) {
            return;
        }

        $changes = [];

        foreach ($this->originalStaffSnapshot as $attribute => $oldValue) {
            $newValue = $staff->getAttribute($attribute);

            if ($oldValue !== $newValue) {
                $changes[$attribute] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        if ($changes !== []) {
            $this->activityLogger->record($actor, 'staff.updated', $staff, [
                'changes' => $changes,
            ]);
        }
    }
}
