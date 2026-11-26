<?php

namespace Tests\Feature;

use App\Filament\Pages\SubscriptionSettings;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformSubscriptionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_load_saved_subscription_settings(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $plan = $this->createPlan('growth', 'Growth');

        PlatformSetting::query()->updateOrCreate(['key' => 'registration_enabled'], ['value' => false]);
        PlatformSetting::query()->updateOrCreate(['key' => 'trial_enabled'], ['value' => false]);
        PlatformSetting::query()->updateOrCreate(['key' => 'trial_days'], ['value' => 14]);
        PlatformSetting::query()->updateOrCreate(['key' => 'grace_period_days'], ['value' => 5]);
        PlatformSetting::query()->updateOrCreate(['key' => 'default_plan_code'], ['value' => $plan->code]);

        Livewire::actingAs($admin)
            ->test(SubscriptionSettings::class)
            ->assertSet('registrationEnabled', false)
            ->assertSet('trialEnabled', false)
            ->assertSet('trialDays', 14)
            ->assertSet('gracePeriodDays', 5)
            ->assertSet('defaultPlanCode', 'growth')
            ->assertSet('planOptions.growth', 'Growth');
    }

    public function test_platform_admin_can_update_subscription_settings_atomically(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->createPlan('pro', 'Pro');

        Livewire::actingAs($admin)
            ->test(SubscriptionSettings::class)
            ->set('registrationEnabled', false)
            ->set('trialEnabled', true)
            ->set('trialDays', 21)
            ->set('gracePeriodDays', 4)
            ->set('defaultPlanCode', 'pro')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(false, PlatformSetting::query()->where('key', 'registration_enabled')->firstOrFail()->value);
        $this->assertSame(true, PlatformSetting::query()->where('key', 'trial_enabled')->firstOrFail()->value);
        $this->assertSame(21, PlatformSetting::query()->where('key', 'trial_days')->firstOrFail()->value);
        $this->assertSame(4, PlatformSetting::query()->where('key', 'grace_period_days')->firstOrFail()->value);
        $this->assertSame('pro', PlatformSetting::query()->where('key', 'default_plan_code')->firstOrFail()->value);
    }

    public function test_inactive_plan_cannot_be_selected_as_the_default_plan(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->createPlan('inactive', 'Inactive', false);

        Livewire::actingAs($admin)
            ->test(SubscriptionSettings::class)
            ->set('defaultPlanCode', 'inactive')
            ->call('save')
            ->assertHasErrors(['defaultPlanCode']);

        $this->assertSame(
            'starter',
            PlatformSetting::query()->where('key', 'default_plan_code')->firstOrFail()->value,
        );
    }

    public function test_invalid_trial_and_grace_period_values_are_rejected_without_saving(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->createPlan('pro', 'Pro');

        Livewire::actingAs($admin)
            ->test(SubscriptionSettings::class)
            ->set('trialDays', -1)
            ->set('gracePeriodDays', 3651)
            ->call('save')
            ->assertHasErrors(['trialDays', 'gracePeriodDays']);

        $this->assertSame(30, PlatformSetting::query()->where('key', 'trial_days')->firstOrFail()->value);
        $this->assertSame(3, PlatformSetting::query()->where('key', 'grace_period_days')->firstOrFail()->value);
    }

    public function test_only_active_tenantless_platform_admin_can_access_settings(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $inactiveAdmin = User::factory()->superAdmin()->create(['is_active' => false]);
        $owner = User::factory()->withRole('tenant_owner')->create();

        $this->actingAs($admin);
        $this->assertTrue(SubscriptionSettings::canAccess());

        $this->actingAs($inactiveAdmin);
        $this->assertFalse(SubscriptionSettings::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(SubscriptionSettings::canAccess());
    }

    public function test_changing_default_trial_settings_does_not_mutate_existing_subscription(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = Tenant::factory()->create();
        $plan = $this->createPlan('pro', 'Pro');
        $trialEndsAt = now()->addDays(30);

        $subscription = Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now(),
            'trial_ends_at' => $trialEndsAt,
        ]);

        Livewire::actingAs($admin)
            ->test(SubscriptionSettings::class)
            ->set('trialDays', 14)
            ->set('defaultPlanCode', 'pro')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($subscription->fresh()->trial_ends_at->equalTo($trialEndsAt));
    }

    private function createPlan(string $code, string $name, bool $active = true): Plan
    {
        return Plan::query()->create([
            'code' => $code,
            'name' => $name,
            'monthly_price' => 99000,
            'is_active' => $active,
        ]);
    }
}
