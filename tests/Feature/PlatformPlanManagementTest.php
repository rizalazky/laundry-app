<?php

namespace Tests\\Feature;

use App\\Filament\\Resources\\Plans\\Pages\\CreatePlan;
use App\\Filament\\Resources\\Plans\\PlanResource;
use App\\Models\\Plan;
use App\\Models\\Subscription;
use App\\Models\\Tenant;
use App\\Models\\User;
use Filament\\Panel;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Livewire\\Livewire;
use Tests\\TestCase;

class PlatformPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_plan_catalog_and_create_a_plan_with_entitlements(): void
    {
        $admin = User::factory()->superAdmin()->create();

        Livewire::actingAs($admin)
            ->test(CreatePlan::class)
            ->fillForm([
                'name' => 'Growth',
                'code' => 'growth',
                'description' => 'Paket pertumbuhan bisnis.',
                'monthly_price' => '150000',
                'yearly_price' => '1500000',
                'currency' => 'IDR',
                'is_active' => true,
                'sort_order' => 4,
                'features' => [
                    ['feature_code' => 'max_outlets', 'value' => '5'],
                    ['feature_code' => 'custom_reports', 'value' => 'true'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::query()->where('code', 'growth')->firstOrFail();

        $this->assertSame('150000.00', $plan->monthly_price);
        $this->assertSame('1500000.00', $plan->yearly_price);
        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_code' => 'max_outlets',
            'value' => '"5"',
        ]);
        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_code' => 'custom_reports',
            'value' => '"true"',
        ]);
    }

    public function test_only_active_super_admin_without_tenant_can_manage_plan_catalog(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $owner = User::factory()->withRole('tenant_owner')->create();
        $inactiveAdmin = User::factory()->superAdmin()->create(['is_active' => false]);

        $this->assertTrue(PlanResource::canAccess());
        $this->assertTrue($admin->canAccessPanel(Panel::make()->id('admin')));
        
        auth()->logout();
        $this->actingAs($owner);
        $this->assertFalse(PlanResource::canAccess());
        $this->assertFalse($owner->canAccessPanel(Panel::make()->id('admin')));

        auth()->logout();
        $this->actingAs($inactiveAdmin);
        $this->assertFalse(PlanResource::canAccess());
    }

    public function test_plan_with_existing_subscriptions_cannot_be_deleted(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'code' => 'legacy-plan',
            'name' => 'Legacy',
            'monthly_price' => 100000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now(),
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($admin);

        $this->assertFalse(PlanResource::canDelete($plan));
        $this->assertTrue(PlanResource::canEdit($plan));
    }
}
