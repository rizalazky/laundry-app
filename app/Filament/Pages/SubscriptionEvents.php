<?php

namespace App\Filament\Pages;

use App\Models\SubscriptionEvent;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class SubscriptionEvents extends Page
{
    protected string $view = 'filament.pages.subscription-events';

    protected static ?string $slug = 'subscription-events';

    protected static ?string $navigationLabel = 'Subscription Audit';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 80;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === 'super_admin';
    }

    protected function getViewData(): array
    {
        return [
            'events' => SubscriptionEvent::query()
                ->with(['tenant', 'subscription.plan', 'invoice', 'payment', 'actor', 'fromPlan', 'toPlan'])
                ->latest('occurred_at')
                ->limit(100)
                ->get(),
        ];
    }
}
