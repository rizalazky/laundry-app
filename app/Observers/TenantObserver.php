<?php

namespace App\Observers;

use App\Actions\Accounting\EnsureDefaultChartOfAccounts;
use App\Models\Tenant;

class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        app(EnsureDefaultChartOfAccounts::class)->handle($tenant);
    }
}
