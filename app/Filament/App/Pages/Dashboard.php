<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->tenant_id !== null
            && $user->hasPermissionTo('dashboard.view');
    }
}
