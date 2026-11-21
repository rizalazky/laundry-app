<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(User $actor, string $action, Model $subject, array $properties = []): ActivityLog
    {
        if (
            ! $actor->is_active
            || $actor->tenant_id === null
            || (Auth::hasUser() && (int) Auth::id() !== (int) $actor->getKey())
        ) {
            throw new DomainException('Aktivitas tidak dapat dicatat untuk pengguna ini.');
        }

        $subjectTenantId = $subject instanceof Tenant
            ? $subject->getKey()
            : $subject->getAttribute('tenant_id');

        if ((int) $subjectTenantId !== (int) $actor->tenant_id) {
            throw new DomainException('Aktivitas lintas tenant tidak diizinkan.');
        }

        return ActivityLog::query()->create([
            'tenant_id' => $actor->tenant_id,
            'user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'properties' => $properties,
        ]);
    }
}
