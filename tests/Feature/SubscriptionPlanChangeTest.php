<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionInvoiceService;
use App\Services\SubscriptionPlanChangeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_schedule_a_plan_change_for_the_end_of_the_current_period(): void
    {
        [$tenant, $owner, $currentPlan, $subscription] = $this->makeSubscription();
        $targetPlan = $this->makePlan('enterprise');

        $this->actingAs($owner);

        $updated = app(SubscriptionPlanChangeService::class)->scheduleChange($tenant, $targetPlan);

        $this->assertSame($currentPlan->id, $updated->plan_id);
        $this->assertSame($targetPlan->id, $updated->scheduled_plan_id);
        $this->assertTrue($updated->scheduled_change_at->equalTo($subscription->current_period_end));
        $this->assertSame($currentPlan->id, $subscription->fresh()->plan_id);
    }

    public function test_owner_can_cancel_a_scheduled_plan_change(): void
    {
        [$tenant, $owner, , $subscription] = $this->makeSubscription();
        $targetPlan = $this->makePlan('enterprise');
        $subscription->forceFill([
            'scheduled_plan_id' => $targetPlan->id,
            'scheduled_change_at' => $subscription->current_period_end,
        ])->save();

        $this->actingAs($owner);

        $updated = app(SubscriptionPlanChangeService::class)->cancelScheduledChange($tenant);

        $this->assertNull($updated->scheduled_plan_id);
        $this->assertNull($updated->scheduled_change_at);
    }

    public function test_due_scheduled_plan_changes_are_applied_and_cleared(): void
    {
        [, , $currentPlan, $subscription] = $this->makeSubscription();
        $targetPlan = $this->makePlan('enterprise');
        $subscription->forceFill([
            'scheduled_plan_id' => $targetPlan->id,
            'scheduled_change_at' => now()->subMinute(),
        ])->save();

        $count = app(SubscriptionPlanChangeService::class)->applyDueChanges();

        $this->assertSame(1, $count);
        $this->assertSame($targetPlan->id, $subscription->fresh()->plan_id);
        $this->assertNull($subscription->fresh()->scheduled_plan_id);
        $this->assertNull($subscription->fresh()->scheduled_change_at);
        $this->assertNotSame($currentPlan->id, $subscription->fresh()->plan_id);
    }

    public function test_scheduling_is_blocked_while_an_open_invoice_exists(): void
    {
        [$tenant, $owner, , $subscription] = $this->makeSubscription();
        $targetPlan = $this->makePlan('enterprise');

        $this->actingAs($owner);
        app(SubscriptionInvoiceService::class)->createRenewalInvoice($tenant);

        $this->expectException(ValidationException::class);
        app(SubscriptionPlanChangeService::class)->scheduleChange($tenant, $targetPlan);
    }

    public function test_staff_and_other_tenant_owners_cannot_schedule_plan_changes(): void
    {
        [$tenant, , ,] = $this->makeSubscription();
        $targetPlan = $this->makePlan('enterprise');
        $staff = User::factory()->for($tenant)->withRole('cashier')->create();

        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);
        app(SubscriptionPlanChangeService::class)->scheduleChange($tenant, $targetPlan);
    }

    private function makeSubscription(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $plan = $this->makePlan('pro');

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        return [$tenant, $owner, $plan, $subscription];
    }

    private function makePlan(string $prefix): Plan
    {
        return Plan::query()->create([
            'code' => $prefix.'-'.Str::lower(Str::random(8)),
            'name' => ucfirst($prefix),
            'monthly_price' => '250000.00',
            'yearly_price' => '2500000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }
}
