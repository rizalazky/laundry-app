<?php

namespace App\Filament\Pages;

use App\Models\SubscriptionEvent;
use App\Services\SubscriptionEventRecorder;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class SubscriptionPaymentReviews extends Page
{
    protected string $view = 'filament.pages.subscription-payment-reviews';

    protected static ?string $slug = 'subscription-payment-reviews';

    protected static ?string $navigationLabel = 'Payment Reviews';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 79;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === 'super_admin';
    }

    /**
     * Return late successful payments that have not yet been acknowledged by a platform admin.
     */
    public function getPendingReviewEvents()
    {
        return SubscriptionEvent::query()
            ->where('event_type', 'payment_received')
            ->whereJsonContains('metadata->requires_manual_review', true)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('subscription_events as review_events')
                    ->whereColumn('review_events.payment_id', 'subscription_events.payment_id')
                    ->where('review_events.event_type', 'late_payment_reviewed');
            })
            ->with(['tenant', 'subscription.plan', 'invoice', 'payment'])
            ->latest('occurred_at')
            ->get();
    }

    public function markReviewed(int $eventId): void
    {
        abort_unless(static::canAccess(), 403);

        DB::transaction(function () use ($eventId): void {
            $event = SubscriptionEvent::query()
                ->whereKey($eventId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $event->event_type === 'payment_received'
                    && data_get($event->metadata, 'requires_manual_review') === true
                    && $event->payment_id !== null,
                404,
            );

            $alreadyReviewed = SubscriptionEvent::query()
                ->where('payment_id', $event->payment_id)
                ->where('event_type', 'late_payment_reviewed')
                ->exists();

            if ($alreadyReviewed) {
                return;
            }

            app(SubscriptionEventRecorder::class)->record(
                $event->subscription,
                'late_payment_reviewed',
                [
                    'invoice_id' => $event->invoice_id,
                    'payment_id' => $event->payment_id,
                    'metadata' => [
                        'reviewed_event_id' => $event->getKey(),
                    ],
                    'occurred_at' => now(),
                ],
            );
        });
    }

    protected function getViewData(): array
    {
        return [
            'reviewEvents' => $this->getPendingReviewEvents(),
        ];
    }
}
