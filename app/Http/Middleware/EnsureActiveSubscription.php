<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $branch = Filament::getTenant();

        if ($branch && ! $branch->tenant?->hasActiveSubscription()) {
            abort(403, 'Masa aktif langganan bisnis Anda telah berakhir.');
        }

        return $next($request);
    }
}