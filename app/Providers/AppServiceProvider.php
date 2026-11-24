<?php

namespace App\Providers;

use App\Listeners\StartTenantTrialAfterEmailVerification;
use App\Models\Tenant;
use App\Models\StockMovement;
use App\Models\Journal;
use App\Observers\TenantObserver;
use App\Observers\StockMovementObserver;
use App\Observers\JournalObserver;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
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
        Journal::observe(JournalObserver::class);

        Event::listen(Verified::class, StartTenantTrialAfterEmailVerification::class);
    }
}
