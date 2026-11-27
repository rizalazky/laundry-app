<?php

namespace Tests\Feature;

use App\Actions\Accounting\FinanceActionAuthorization;
use App\Actions\Orders\OrderActionAuthorization;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\RolePermission;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionAuthorizationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_actions_remain_writable_during_the_subscription_grace_period(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->subscription($tenant, now()->subDay(), now()->addDay());
        $this->actingAs($owner);

        $authorized = app(OrderActionAuthorization::class)->authorizeBranch(
            $owner,
            $branch,
            ['tenant_owner'],
        );

        $this->assertSame($branch->id, $authorized->id);
    }

    public function test_finance_actions_remain_writable_during_the_subscription_grace_period(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->grantFinancePermission($tenant, 'tenant_owner');
        $this->subscription($tenant, now()->subDay(), now()->addDay());
        $this->actingAs($owner);

        $authorized = app(FinanceActionAuthorization::class)->authorizeBranch(
            (int) $tenant->id,
            (int) $branch->id,
        );

        $this->assertSame($branch->id, $authorized->id);
    }

    private function subscription(Tenant $tenant, $periodEnd, $graceEnd): Subscription
    {
        $plan = Plan::query()->create([
            'code' => 'test-' . fake()->unique()->lexify('??????'),
            'name' => 'Test Plan',
            'monthly_price' => 99000,
            'is_active' => true,
        ]);

        return Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subDays(31),
            'current_period_end' => $periodEnd,
            'grace_ends_at' => $graceEnd,
        ]);
    }

    private function grantFinancePermission(Tenant $tenant, string $role): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'finance.manage'],
            ['name' => 'Kelola Keuangan', 'group' => 'Keuangan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
