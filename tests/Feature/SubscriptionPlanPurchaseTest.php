<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionInvoiceActivationService;
use App\Services\SubscriptionInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionPlanPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_more_expensive_plan_creates_prorated_upgrade_invoice_without_changing_active_plan_before_payment(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        [$tenant, $owner, $currentPlan, $subscription] = $this->makeActiveSubscription('100000.00');
        $targetPlan = $this->makePlan('200000.00', 'Upgrade');

        $this->actingAs($owner);
        $invoice = app(SubscriptionInvoiceService::class)->createPlanPurchaseInvoice($tenant, $targetPlan);

        $this->assertSame('upgrade', $invoice->type);
        $this->assertSame('50000.00', $invoice->total_amount);
        $this->assertSame('200000.00', $invoice->unit_price_snapshot);
        $this->assertTrue($invoice->period_start->equalTo(now()));
        $this->assertTrue($invoice->period_end->equalTo($subscription->current_period_end));
        $this->assertSame($currentPlan->id, $subscription->fresh()->plan_id);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_cheaper_plan_purchase_is_paid_for_full_price_and_starts_after_current_period(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        [$tenant, $owner, , $subscription] = $this->makeActiveSubscription('200000.00');
        $targetPlan = $this->makePlan('100000.00', 'Lower');

        $this->actingAs($owner);
        $invoice = app(SubscriptionInvoiceService::class)->createPlanPurchaseInvoice($tenant, $targetPlan);

        $this->assertSame('renewal', $invoice->type);
        $this->assertSame('100000.00', $invoice->total_amount);
        $this->assertTrue($invoice->period_start->equalTo($subscription->current_period_end));
        $this->assertTrue($invoice->period_end->equalTo($subscription->current_period_end->copy()->addMonth()));
        $this->assertSame('200000.00', $subscription->fresh()->plan->monthly_price);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_paid_cheaper_plan_waits_until_its_period_start_before_activation(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        [$tenant, , $currentPlan, $subscription] = $this->makeActiveSubscription('200000.00');
        $targetPlan = $this->makePlan('100000.00', 'Lower');

        $invoice = SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $targetPlan->id,
            'invoice_number' => 'SUB-TEST-LOWER-001',
            'type' => 'renewal',
            'plan_code_snapshot' => $targetPlan->code,
            'plan_name_snapshot' => $targetPlan->name,
            'billing_interval' => 'monthly',
            'unit_price_snapshot' => '100000.00',
            'subtotal' => '100000.00',
            'discount_amount' => '0.00',
            'total_amount' => '100000.00',
            'currency' => 'IDR',
            'status' => 'paid',
            'period_start' => $subscription->current_period_end,
            'period_end' => $subscription->current_period_end->copy()->addMonth(),
            'paid_at' => now(),
        ]);

        $activation = app(SubscriptionInvoiceActivationService::class);
        $this->assertSame('scheduled', $activation->activateIfDue($invoice));
        $this->assertSame($currentPlan->id, $subscription->fresh()->plan_id);

        Carbon::setTestNow($invoice->period_start);
        $this->assertSame('activated', $activation->activateIfDue($invoice));
        $this->assertSame('plan_changed', \\App\\Models\\SubscriptionEvent::query()->latest('id')->value('event_type'));
        $this->assertSame($targetPlan->id, $subscription->fresh()->plan_id);
        $this->assertTrue($subscription->fresh()->current_period_start->equalTo($invoice->period_start));
        $this->assertTrue($tenant->fresh()->subscription_ends_at->equalTo($invoice->period_end));
    }

    public function test_paid_upgrade_changes_plan_immediately_and_preserves_existing_period_boundaries(): void
    {
        Carbon::setTestNow('2026-11-26 12:00:00');
        [$tenant, , $currentPlan, $subscription] = $this->makeActiveSubscription('100000.00');
        $targetPlan = $this->makePlan('200000.00', 'Upgrade');
        $originalStart = $subscription->current_period_start->copy();
        $originalEnd = $subscription->current_period_end->copy();

        $invoice = SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $targetPlan->id,
            'invoice_number' => 'SUB-TEST-UPGRADE-001',
            'type' => 'upgrade',
            'plan_code_snapshot' => $targetPlan->code,
            'plan_name_snapshot' => $targetPlan->name,
            'billing_interval' => 'monthly',
            'unit_price_snapshot' => '200000.00',
            'subtotal' => '50000.00',
            'discount_amount' => '150000.00',
            'total_amount' => '50000.00',
            'currency' => 'IDR',
            'status' => 'paid',
            'period_start' => now(),
            'period_end' => $originalEnd,
            'paid_at' => now(),
        ]);

        $this->assertSame('activated', app(SubscriptionInvoiceActivationService::class)->activateIfDue($invoice));
        $this->assertSame('plan_upgraded', \\App\\Models\\SubscriptionEvent::query()->latest('id')->value('event_type'));
        $this->assertSame($targetPlan->id, $subscription->fresh()->plan_id);
        $this->assertTrue($subscription->fresh()->current_period_start->equalTo($originalStart));
        $this->assertTrue($subscription->fresh()->current_period_end->equalTo($originalEnd));
        $this->assertSame('active', $subscription->fresh()->status);
    }

    private function makeActiveSubscription(string $currentPrice): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $currentPlan = $this->makePlan($currentPrice, 'Current');
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $currentPlan->id,
            'status' => 'active',
            'trial_starts_at' => now()->subMonth(),
            'trial_ends_at' => now()->subDay(),
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        return [$tenant, $owner, $currentPlan, $subscription];
    }

    private function makePlan(string $monthlyPrice, string $prefix): Plan
    {
        return Plan::query()->create([
            'code' => Str::lower($prefix).'-'.Str::lower(Str::random(8)),
            'name' => $prefix,
            'monthly_price' => $monthlyPrice,
            'yearly_price' => (string) ((float) $monthlyPrice * 10),
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }
}
