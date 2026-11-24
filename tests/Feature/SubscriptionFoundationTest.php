<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_features_store_boolean_and_limit_values_as_json(): void
    {
        $plan = Plan::query()->create([
            'code' => 'starter',
            'name' => 'Starter',
            'monthly_price' => 99000,
        ]);

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'inventory',
            'value' => true,
        ]);

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'max_outlets',
            'value' => 3,
        ]);

        $this->assertTrue($plan->features()->where('feature_code', 'inventory')->firstOrFail()->value);
        $this->assertSame(3, $plan->features()->where('feature_code', 'max_outlets')->firstOrFail()->value);
    }

    public function test_tenant_owns_one_subscription_and_subscription_links_to_its_plan(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 199000,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now(),
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->assertSame($subscription->id, $tenant->subscription()->firstOrFail()->id);
        $this->assertSame($plan->id, $subscription->plan()->firstOrFail()->id);
        $this->assertSame($tenant->id, $subscription->tenant()->firstOrFail()->id);
    }

    public function test_database_rejects_a_second_subscription_for_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'code' => 'starter',
            'name' => 'Starter',
            'monthly_price' => 99000,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
        ]);

        $this->expectException(QueryException::class);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_authenticated_tenant_cannot_read_or_create_another_tenants_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'code' => 'starter',
            'name' => 'Starter',
            'monthly_price' => 99000,
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        $subscription = Subscription::query()->create([
            'tenant_id' => $foreignTenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
        ]);

        $this->assertSame($tenant->id, $subscription->fresh()->tenant_id);
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(
            0,
            Subscription::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $foreignTenant->id)
                ->count(),
        );
    }

    public function test_subscription_service_reads_platform_trial_and_grace_configuration(): void
    {
        PlatformSetting::query()->create(['key' => 'trial_enabled', 'value' => false]);
        PlatformSetting::query()->create(['key' => 'trial_days', 'value' => 14]);
        PlatformSetting::query()->create(['key' => 'grace_period_days', 'value' => 5]);
        PlatformSetting::query()->create(['key' => 'default_plan_code', 'value' => 'pro']);

        $plan = Plan::query()->create([
            'code' => 'pro',
            'name' => 'Pro',
            'monthly_price' => 199000,
        ]);

        $service = app(SubscriptionService::class);

        $this->assertFalse($service->trialEnabled());
        $this->assertSame(14, $service->trialDays());
        $this->assertSame(5, $service->gracePeriodDays());
        $this->assertSame($plan->id, $service->defaultPlan()?->id);
    }

    public function test_subscription_service_uses_safe_defaults_when_platform_settings_are_missing(): void
    {
        $service = app(SubscriptionService::class);

        $this->assertTrue($service->trialEnabled());
        $this->assertSame(30, $service->trialDays());
        $this->assertSame(3, $service->gracePeriodDays());
        $this->assertNull($service->defaultPlan());
    }
}
