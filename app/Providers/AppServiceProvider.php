<?php

namespace App\Providers;

use App\Models\Tenant;
use App\Models\StockMovement;
use App\Models\Journal;
use App\Observers\TenantObserver;
use App\Observers\StockMovementObserver;
use App\Observers\JournalObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Tenant::observe(TenantObserver::class);
        StockMovement::observe(StockMovementObserver::class);
        Journal::observe(JournalObserver::class);
    }
}
