<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\BranchPolicy;
use App\Policies\UserPolicy;
use App\Services\EntitlementService;
use App\Services\TenantWriteGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_is_writable_until_its_configured_end_then_becomes_read_only(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = $this->subscription($tenant, 'trialing', [
            'trial_ends_at' => now()->addDay(),
        ]);
        $service = app(EntitlementService::class);

        $this->assertSame('trialing', $service->accessState($tenant));
        $this->assertTrue($service->canWrite($tenant));

        $subscription->forceFill(['trial_ends_at' => now()->subSecond()])->save();

        $this->assertSame('read_only', $service->accessState($tenant));
        $this->assertFalse($service->canWrite($tenant));
    }

    public function test_active_subscription_enters_grace_period_before_becoming_read_only(): void
    {
        $tenant = Tenant::factory()->create();
        $subscription = $this->subscription($tenant, 'active', [
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
            'grace_ends_at' => null,
        ]);
        $service = app(EntitlementService::class);

        $this->assertSame('grace', $service->accessState($tenant));
        $this->assertTrue($service->canWrite($tenant));

        $subscription->forceFill(['current_period_end' => now()->subDays(5)])->save();

        $this->assertSame('read_only', $service->accessState($tenant));
        $this->assertFalse($service->canWrite($tenant));
    }

    public function test_past_due_subscription_is_writable_only_during_grace_period(): void
    {
        $tenant = Tenant::factory()->create();
        $this->subscription($tenant, 'past_due', [
            'current_period_end' => now()->subDay(),
            'grace_ends_at' => now()->addDay(),
        ]);

        $service = app(EntitlementService::class);

        $this->assertSame('grace', $service->accessState($tenant));
        $this->assertTrue($service->canWrite($tenant));
    }

    public function test_active_subscription_without_period_end_respects_legacy_tenant_expiry(): void
    {
        $tenant = Tenant::factory()->create([
            'subscription_ends_at' => now()->subDay(),
        ]);
        $this->subscription($tenant, 'active');

        $this->assertSame('read_only', app(EntitlementService::class)->accessState($tenant));
    }

    public function test_read_only_tenant_can_read_but_cannot_authorize_write_abilities(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['code' => 'READONLY']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->subscription($tenant, 'trialing', [
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($owner);

        $this->assertTrue(Gate::allows('viewAny', Branch::class));
        $this->assertTrue(Gate::allows('view', $branch));
        $this->assertFalse(Gate::allows('create', Branch::class));
        $this->assertFalse(Gate::allows('update', $branch));
    }

    public function test_read_only_tenant_cannot_persist_tenant_owned_eloquent_models(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->subscription($tenant, 'trialing', [
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($owner);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Branch::factory()->for($tenant)->create(['code' => 'BLOCKED']);
    }

    public function test_tenant_write_guard_recognizes_tenant_model_primary_key_for_read_only_writes(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->subscription($tenant, 'trialing', [
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($owner);
        $this->expectException(\\Illuminate\\Auth\\Access\\AuthorizationException::class);

        app(\\App\\Services\\TenantWriteGuard::class)->assertModelWriteAllowed($tenant);
    }

    public function test_plan_feature_values_are_resolved_from_the_tenant_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = $this->plan();
        $this->subscription($tenant, 'active', ['plan_id' => $plan->id]);

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'inventory',
            'value' => true,
        ]);
        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'advanced_reports',
            'value' => false,
        ]);

        $service = app(EntitlementService::class);

        $this->assertTrue($service->hasFeature($tenant, 'inventory'));
        $this->assertFalse($service->hasFeature($tenant, 'advanced_reports'));
        $this->assertFalse($service->hasFeature($tenant, 'unknown_feature'));
    }

    public function test_plan_outlet_and_user_limits_are_enforced_by_policies(): void
    {
        $tenant = Tenant::factory()->create(['max_branches' => 10]);
        $plan = $this->plan();
        $this->subscription($tenant, 'active', ['plan_id' => $plan->id]);

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'max_outlets',
            'value' => 1,
        ]);
        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'max_users',
            'value' => 1,
        ]);

        Branch::factory()->for($tenant)->create(['code' => 'LIMIT']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->assertFalse((new BranchPolicy())->create($owner));
        $this->assertFalse((new UserPolicy())->create($owner));
    }

    public function test_workshops_do_not_consume_the_plan_outlet_limit(): void
    {
        $tenant = Tenant::factory()->create(['max_branches' => 10]);
        $plan = $this->plan();
        $this->subscription($tenant, 'active', ['plan_id' => $plan->id]);

        PlanFeature::query()->create([
            'plan_id' => $plan->id,
            'feature_code' => 'max_outlets',
            'value' => 1,
        ]);

        Branch::factory()->for($tenant)->create([
            'code' => 'WORKSHOP',
            'is_workshop' => true,
        ]);

        $this->assertTrue(app(EntitlementService::class)->canCreateBranch($tenant));
    }

    public function test_tenant_write_guard_rejects_model_writes_for_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        $model = new class extends \Illuminate\Database\Eloquent\Model {};
        $model->setAttribute('tenant_id', $otherTenant->id);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\TenantWriteGuard::class)->assertModelWriteAllowed($model);
    }

    public function test_tenant_write_guard_rejects_branch_references_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($otherTenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        $model = new class extends \Illuminate\Database\Eloquent\Model {};
        $model->setAttribute('tenant_id', $tenant->id);
        $model->setAttribute('branch_id', $foreignBranch->id);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\TenantWriteGuard::class)->assertModelWriteAllowed($model);
    }

    private function subscription(Tenant $tenant, string $status, array $attributes = []): Subscription
    {
        $plan = $attributes['plan_id'] ?? $this->plan()->id;

        return Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan,
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::query()->create([
            'code' => 'plan-' . fake()->unique()->lexify('??????'),
            'name' => 'Test Plan',
            'monthly_price' => 99000,
            'is_active' => true,
        ]);
    }
}
