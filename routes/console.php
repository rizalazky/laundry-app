<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loyalty:expire-points')->everyMinute()->withoutOverlapping();
Schedule::command('mass-invoices:run-due')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:activate-paid-invoices')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:sync-lifecycle')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:expire-invoices')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:reconcile-pending-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('subscriptions:send-renewal-reminders')->dailyAt('09:00')->withoutOverlapping();
