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
     * Record an activity for an actor whose identity and permissions have
     * already been verified by the calling domain action.
     *
     * This is intended for service actions that accept an explicit actor and
     * authorize that actor independently of the current HTTP authentication
     * context (for example, CLI jobs and direct action invocations).
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordForAuthorizedActor(User $actor, string $action, Model $subject, array $properties = []): ActivityLog
    {
        $verifiedActor = User::query()->find($actor->getKey());

        if ($verifiedActor === null || ! $verifiedActor->is_active || $verifiedActor->tenant_id === null) {
            throw new DomainException('Aktivitas tidak dapat dicatat untuk pengguna ini.');
        }

        return $this->persist($verifiedActor, $action, $subject, $properties);
    }

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

        return $this->persist($actor, $action, $subject, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function persist(User $actor, string $action, Model $subject, array $properties): ActivityLog
    {
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
