<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlatformSetting;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_basic_pro_and_enterprise_and_uses_basic_as_default_trial_plan(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertDatabaseHas('plans', [
            'code' => 'basic',
            'name' => 'Basic',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('plans', [
            'code' => 'pro',
            'name' => 'Pro',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('plans', [
            'code' => 'enterprise',
            'name' => 'Enterprise',
            'is_active' => true,
        ]);

        $this->assertSame('basic', PlatformSetting::query()
            ->where('key', 'default_plan_code')
            ->value('value'));

        $this->assertSame(3, Plan::query()->count());
    }

    public function test_rerunning_seeder_does_not_overwrite_platform_plan_edits(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $pro->update([
            'monthly_price' => 499000,
            'is_active' => false,
        ]);

        $this->seed(SubscriptionPlanSeeder::class);

        $pro->refresh();

        $this->assertSame('499000.00', $pro->monthly_price);
        $this->assertFalse($pro->is_active);
        $this->assertSame(3, Plan::query()->count());
    }
}
