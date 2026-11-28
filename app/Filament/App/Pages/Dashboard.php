<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public static function canAccess(): bool
    {
        $authenticatedUserId = Auth::id();
        $user = $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('dashboard.view');
    }
}
