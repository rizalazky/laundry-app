<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_active_subscription_enters_grace_then_expires_and_records_each_transition_once(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        $tenant = Tenant::factory()->create();
        $plan = $this->plan();
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:sync-lifecycle')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertTrue($subscription->grace_ends_at->equalTo(now()->addDays(2)));
        $this->assertSame('grace', app(\App\Services\EntitlementService::class)->accessState($tenant));
        $this->assertSame(1, SubscriptionEvent::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame('grace_started', SubscriptionEvent::query()->sole()->event_type);

        Carbon::setTestNow($subscription->grace_ends_at);
        $this->artisan('subscriptions:sync-lifecycle')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('read_only', app(\App\Services\EntitlementService::class)->accessState($tenant));
        $this->assertSame(2, SubscriptionEvent::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame(
            'subscription_expired',
            SubscriptionEvent::query()->where('subscription_id', $subscription->id)->latest('id')->value('event_type'),
        );

        $this->artisan('subscriptions:sync-lifecycle')->assertSuccessful();
        $this->assertSame(2, SubscriptionEvent::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_expired_trial_transitions_directly_to_read_only_and_records_trial_expiry(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        $tenant = Tenant::factory()->create();
        $plan = $this->plan();
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now()->subDays(8),
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:sync-lifecycle')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('read_only', app(\App\Services\EntitlementService::class)->accessState($tenant));
        $event = SubscriptionEvent::query()->sole();
        $this->assertSame('trial_expired', $event->event_type);
        $this->assertSame('trialing', $event->from_status);
        $this->assertSame('expired', $event->to_status);
    }

    public function test_zero_day_grace_period_expires_active_subscription_without_a_writable_gap(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        \App\Models\PlatformSetting::query()->updateOrCreate(
            ['key' => 'grace_period_days'],
            ['value' => 0],
        );

        $tenant = Tenant::factory()->create();
        $plan = $this->plan();
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subSecond(),
        ]);

        $this->artisan('subscriptions:sync-lifecycle')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('read_only', app(\App\Services\EntitlementService::class)->accessState($tenant));
        $this->assertSame('subscription_expired', SubscriptionEvent::query()->sole()->event_type);
    }

    private function plan(): Plan
    {
        return Plan::query()->create([
            'code' => 'plan-'.Str::lower(Str::random(8)),
            'name' => 'Lifecycle Plan',
            'monthly_price' => '99000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }
}
