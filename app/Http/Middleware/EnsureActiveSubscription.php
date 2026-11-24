<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $branch = Filament::getTenant();
        $tenant = $branch?->tenant;

        if ($branch !== null) {
            abort_unless($tenant !== null && $tenant->is_active, 403, 'Akun bisnis Anda sedang dinonaktifkan.');

            $entitlements = app(EntitlementService::class);
            $request->attributes->set('subscription_access_state', $entitlements->accessState($tenant));
            $request->attributes->set('tenant_read_only', ! $entitlements->canWrite($tenant));
        }

        // Expired subscriptions remain able to load pages and read historical data.
        // Write abilities are denied centrally by the authorization gate.
        return $next($request);
    }
}