<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Auth\Events\Verified;

class StartTenantTrialAfterEmailVerification
{
    public function __construct(
        private SubscriptionService $subscriptions,
    ) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->role !== 'tenant_owner' || $user->tenant === null) {
            return;
        }

        $this->subscriptions->startTrial($user->tenant);
    }
}
