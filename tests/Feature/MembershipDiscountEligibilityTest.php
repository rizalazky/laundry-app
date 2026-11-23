<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipDiscountEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_tenant_membership_is_applied_when_order_is_created_at_another_branch(): void
    {
        $tenant = Tenant::factory()->create(['max_branches' => 2]);
        $activationBranch = Branch::factory()->for($tenant)->create(['code' => 'PUSAT']);
        $checkoutBranch = Branch::factory()->for($tenant)->create(['code' => 'CABANG']);
        $customer = Customer::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create(['price' => 10000]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $tier = MemberTier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Gold',
            'price' => 100000,
            'duration_days' => 30,
            'discount_percent' => 10,
            'is_active' => true,
        ]);

        $membership = CustomerMembership::query()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'member_tier_id' => $tier->id,
            'started_at' => now()->subDay(),
            'expired_at' => now()->addDays(29),
            'status' => CustomerMembership::STATUS_ACTIVE,
            'activated_at_branch_id' => $activationBranch->id,
        ]);

        $order = app(CreateOrderAction::class)->handle(
            branch: $checkoutBranch,
            user: $owner,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 1]],
        );

        $this->assertSame($membership->id, $order->customer_membership_id);
        $this->assertSame($tier->id, $order->member_tier_id);
        $this->assertSame('Gold', $order->member_tier_name_snapshot);
        $this->assertSame('10.00', $order->membership_discount_percent_snapshot);
        $this->assertSame('1000.00', $order->membership_discount);
        $this->assertSame('9000.00', $order->total);
        $this->assertSame($checkoutBranch->id, $order->branch_id);
    }

    public function test_membership_discount_is_not_applied_before_start_or_at_or_after_expiry(): void
    {
        $tenant = Tenant::factory()->create(['max_branches' => 2]);
        $branch = Branch::factory()->for($tenant)->create(['code' => 'PUSAT']);
        $service = Service::factory()->for($tenant)->create(['price' => 10000]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $tier = MemberTier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Silver',
            'price' => 50000,
            'duration_days' => 30,
            'discount_percent' => 15,
            'is_active' => true,
        ]);

        $futureCustomer = Customer::factory()->for($tenant)->create();
        CustomerMembership::query()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $futureCustomer->id,
            'member_tier_id' => $tier->id,
            'started_at' => now()->addMinute(),
            'expired_at' => now()->addDays(31),
            'status' => CustomerMembership::STATUS_ACTIVE,
        ]);

        $expiredCustomer = Customer::factory()->for($tenant)->create();
        CustomerMembership::query()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $expiredCustomer->id,
            'member_tier_id' => $tier->id,
            'started_at' => now()->subDays(31),
            'expired_at' => now()->subSecond(),
            'status' => CustomerMembership::STATUS_ACTIVE,
        ]);

        foreach ([$futureCustomer, $expiredCustomer] as $customer) {
            $order = app(CreateOrderAction::class)->handle(
                branch: $branch,
                user: $owner,
                customerId: $customer->id,
                items: [['service_id' => $service->id, 'quantity' => 1]],
            );

            $this->assertInstanceOf(Order::class, $order);
            $this->assertNull($order->customer_membership_id);
            $this->assertNull($order->member_tier_id);
            $this->assertSame('0.00', $order->membership_discount);
            $this->assertSame('0.00', $order->discount);
            $this->assertSame('10000.00', $order->total);
        }
    }
}
