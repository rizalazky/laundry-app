<?php

namespace App\Console\Commands;

use App\Services\SubscriptionPlanChangeService;
use Illuminate\Console\Command;

class ApplyScheduledSubscriptionPlanChanges extends Command
{
    protected $signature = 'subscriptions:apply-scheduled-plan-changes';

    protected $description = 'Apply subscription plan changes scheduled for the end of the current period';

    public function handle(SubscriptionPlanChangeService $service): int
    {
        $count = $service->applyDueChanges();

        $this->info("{$count} perubahan paket subscription diterapkan.");

        return self::SUCCESS;
    }
}
