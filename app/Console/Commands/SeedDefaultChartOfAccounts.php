<?php

namespace App\Console\Commands;

use App\Actions\Accounting\EnsureDefaultChartOfAccounts;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedDefaultChartOfAccounts extends Command
{
    protected $signature = 'accounting:seed-default-coa';

    protected $description = 'Create missing default chart-of-accounts entries for every tenant';

    public function handle(EnsureDefaultChartOfAccounts $ensureDefaultChartOfAccounts): int
    {
        $tenantCount = 0;

        Tenant::query()
            ->select('id')
            ->chunkById(100, function ($tenants) use ($ensureDefaultChartOfAccounts, &$tenantCount): void {
                foreach ($tenants as $tenant) {
                    $ensureDefaultChartOfAccounts->handle((int) $tenant->id);
                    $tenantCount++;
                }
            });

        $this->info("Default chart of accounts ensured for {$tenantCount} tenant(s).");

        return self::SUCCESS;
    }
}
