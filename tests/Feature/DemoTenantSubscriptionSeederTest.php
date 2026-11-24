<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoTenantSubscriptionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_tenant_seeder_provisions_a_trial_subscription_idempotently(): void
    {
        config([
            'app.seed_demo_tenant.enabled' => true,
            'app.seed_demo_tenant.name' => 'Demo Laundry',
            'app.seed_demo_tenant.slug' => 'demo-laundry-test',
            'app.seed_demo_tenant.owner_email' => 'demo-owner@example.test',
            'app.seed_demo_tenant.owner_password' => 'StrongPassword123',
        ]);

        $seeder = app(DemoTenantSeeder::class);

        $seeder->run();
        $seeder->run();

        $tenant = Tenant::query()->where('slug', 'demo-laundry-test')->firstOrFail();
        $subscription = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame('trialing', $subscription->status);
        $this->assertSame('starter', $subscription->plan->code);
        $this->assertNotNull($subscription->trial_starts_at);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertSame(1, Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->count());
    }
}
