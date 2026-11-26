<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Illuminate\Support\Facades\Auth;

class SubscriptionEventRecorder
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function record(Subscription $subscription, string $eventType, array $attributes = []): SubscriptionEvent
    {
        return SubscriptionEvent::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->getKey(),
            'invoice_id' => $attributes['invoice_id'] ?? null,
            'payment_id' => $attributes['payment_id'] ?? null,
            'actor_id' => $attributes['actor_id'] ?? Auth::id(),
            'event_type' => $eventType,
            'from_status' => $attributes['from_status'] ?? null,
            'to_status' => $attributes['to_status'] ?? null,
            'from_plan_id' => $attributes['from_plan_id'] ?? null,
            'to_plan_id' => $attributes['to_plan_id'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }
}
