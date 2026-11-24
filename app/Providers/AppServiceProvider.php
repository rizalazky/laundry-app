<?php

namespace App\Providers;

use App\Listeners\StartTenantTrialAfterEmailVerification;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\JournalObserver;
use App\Observers\StockMovementObserver;
use App\Observers\TenantObserver;
use App\Services\EntitlementService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Tenant::observe(TenantObserver::class);
        StockMovement::observe(StockMovementObserver::class);
        \App\Models\Journal::observe(JournalObserver::class);

        Event::listen(Verified::class, StartTenantTrialAfterEmailVerification::class);

        Gate::before(function (User $user, string $ability): ?bool {
            $writeAbilities = [
                'create',
                'createAny',
                'update',
                'delete',
                'deleteAny',
                'restore',
                'forceDelete',
                'replicate',
                'reorder',
            ];

            if (! in_array($ability, $writeAbilities, true) || $user->tenant_id === null) {
                return null;
            }

            $tenant = $user->tenant;

            return $tenant !== null && app(EntitlementService::class)->canWrite($tenant)
                ? null
                : false;
        });
    }
}