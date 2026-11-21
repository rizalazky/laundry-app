<?php

namespace App\Observers;

use App\Actions\Accounting\EnsureDefaultChartOfAccounts;
use App\Models\Tenant;

class TenantObserver
{
    public function __construct(
        private EnsureDefaultChartOfAccounts $ensureDefaultChartOfAccounts,
    ) {}

    public function created(Tenant $tenant): void
    {
        $this->ensureDefaultChartOfAccounts->handle($tenant);
    }
}
