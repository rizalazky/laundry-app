<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionRenewalReminder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionRenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_h7_filament_database_notification_to_active_tenant_owner_and_records_audit_event(): void
    {
        Carbon::setTestNow('2026-11-27 09:00:00');

        [$tenant, $owner, $subscription] = $this->activeSubscriptionEndingInDays(7);

        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(
            1,
            $owner->notifications()->where('data->format', 'filament')->count(),
        );

        $notification = $owner->notifications()->sole();
        $this->assertSame('filament', $notification->data['format']);
        $this->assertSame('Masa aktif paket akan berakhir', $notification->data['title']);
        $this->assertSame('subscription_renewal_reminder', $notification->data['viewData']['type']);
        $this->assertSame(7, $notification->data['viewData']['days_remaining']);
        $this->assertDatabaseHas('subscription_renewal_reminders', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'days_before_expiry' => 7,
        ]);
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_renewal_reminder_sent',
        ]);
    }

    public function test_each_reminder_milestone_is_sent_once_for_the_same_paid_period(): void
    {
        Carbon::setTestNow('2026-11-27 09:00:00');

        [, $owner] = $this->activeSubscriptionEndingInDays(7);

        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();
        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

        Carbon::setTestNow('2026-12-03 09:00:00');
        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

        $this->assertSame(3, $owner->notifications()->count());
        $this->assertSame(3, SubscriptionRenewalReminder::query()->count());
        $this->assertSame(
            3,
            SubscriptionEvent::query()
                ->where('event_type', 'subscription_renewal_reminder_sent')
                ->count(),
        );
    }

    public function test_does_not_send_reminder_when_a_paid_renewal_already_covers_the_next_period(): void
    {
        Carbon::setTestNow('2026-11-27 09:00:00');

        [, $owner, $subscription] = $this->activeSubscriptionEndingInDays(7);
        $periodEnd = $subscription->current_period_end;

        SubscriptionInvoice::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'invoice_number' => 'SUB-REMINDER-PAID-001',
            'type' => 'renewal',
            'plan_code_snapshot' => $subscription->plan->code,
            'plan_name_snapshot' => $subscription->plan->name,
            'billing_interval' => 'monthly',
            'unit_price_snapshot' => '99000.00',
            'subtotal' => '99000.00',
            'discount_amount' => '0.00',
            'total_amount' => '99000.00',
            'currency' => 'IDR',
            'status' => 'paid',
            'period_start' => $periodEnd,
            'period_end' => $periodEnd->copy()->addMonth(),
            'paid_at' => now(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

        $this->assertSame(0, $owner->notifications()->count());
        $this->assertSame(0, SubscriptionRenewalReminder::query()->count());
        $this->assertSame(0, SubscriptionEvent::query()->count());
    }

    public function test_skips_inactive_subscriptions_and_tenants_without_an_active_owner(): void
    {
        Carbon::setTestNow('2026-11-27 09:00:00');

        $plan = $this->plan();
        $inactiveTenant = Tenant::factory()->create();
        $inactiveOwner = User::factory()->for($inactiveTenant)->withRole('tenant_owner')->create([
            'is_active' => false,
        ]);

        Subscription::query()->create([
            'tenant_id' => $inactiveTenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(7)->setTime(23, 0),
        ]);

        $ownerlessTenant = Tenant::factory()->create();
        Subscription::query()->create([
            'tenant_id' => $ownerlessTenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(7)->setTime(23, 0),
        ]);

        $expiredTenant = Tenant::factory()->create();
        $expiredOwner = User::factory()->for($expiredTenant)->withRole('tenant_owner')->create();
        Subscription::query()->create([
            'tenant_id' => $expiredTenant->id,
            'plan_id' => $plan->id,
            'status' => 'expired',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(7)->setTime(23, 0),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

        $this->assertSame(0, $inactiveOwner->notifications()->count());
        $this->assertSame(0, $expiredOwner->notifications()->count());
        $this->assertSame(0, SubscriptionRenewalReminder::query()->count());
    }

    /**
     * @return array{Tenant, User, Subscription}
     */
    private function activeSubscriptionEndingInDays(int $days): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $plan = $this->plan();

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays($days)->setTime(23, 0),
        ]);

        return [$tenant, $owner, $subscription->load('plan')];
    }

    private function plan(): Plan
    {
        return Plan::query()->create([
            'code' => 'reminder-'.Str::lower(Str::random(8)),
            'name' => 'Reminder Plan',
            'monthly_price' => '99000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }
}
