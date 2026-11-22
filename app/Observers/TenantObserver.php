<?php

namespace App\\Observers;

use App\\Actions\\Accounting\\EnsureDefaultChartOfAccounts;
use App\\Models\\Tenant;
use Illuminate\\Support\\Facades\\DB;

class TenantObserver
{
    public function __construct(
        private EnsureDefaultChartOfAccounts $ensureDefaultChartOfAccounts,
    ) {}

    public function created(Tenant $tenant): void
    {
        $this->ensureDefaultChartOfAccounts->handle($tenant);
    }

    public function forceDeleting(Tenant $tenant): void
    {
        DB::table('location_stocks')
            ->where('tenant_id', $tenant->getKey())
            ->delete();
    }
}
