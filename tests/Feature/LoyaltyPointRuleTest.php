<?php

namespace Tests\Feature;

use App\Actions\Loyalty\AwardOrderPoints;
use App\Actions\Loyalty\RedeemCustomerReward;
use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Console\Commands\ExpireLoyaltyPoints;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\LoyaltyPointRule;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\PointLog;
use App\Models\PointReward;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyPointRuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private User $owner;
    private Customer $customer;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->for($this->tenant)->create(['code' => 'BDG']);
        $this->cashier = User::factory()->for($this->tenant)->withRole('cashier')->create();
        $this->cashier->branches()->attach($this->branch);
        $this->owner = User::factory()->for($this->tenant)->withRole('tenant_owner')->create();
        $this->customer = Customer::factory()->for($this->tenant)->create();
        $this->service = Service::factory()->for($this->tenant)->create(['price' => 7000]);
    }

    public function test_paid_completed_order_awards_only_the_most_specific_matching_program_once(): void
    {
        $gold = $this->createTier('Gold');
        CustomerMembership::query()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'member_tier_id' => $gold->id, 'started_at' => now()->subDay(),
            'expired_at' => now()->addDays(30), 'status' => CustomerMembership::STATUS_ACTIVE,
        ]);

        $this->createRule(LoyaltyPointRule::TARGET_ALL_CUSTOMERS, 1, null, 10000, 1);
        $this->createRule(LoyaltyPointRule::TARGET_ALL_MEMBERS, 2, null, 10000, 2);
        $goldRule = $this->createRule(LoyaltyPointRule::TARGET_MEMBER_TIER, 5, $gold->id, 50000, 5);

        $order = $this->createPaidCompletedOrder();
        $log = PointLog::query()->where('transaction_id', $order->id)->sole();

        $this->assertSame($goldRule->id, $log->loyalty_point_rule_id);
        $this->assertSame(5, $log->points);
        $this->assertTrue($log->expires_at->equalTo($goldRule->ends_at));
        $this->assertSame(5, (int) $this->customer->fresh()->loyalty_points);
        $this->assertDatabaseHas('loyalty_point_lots', [
            'transaction_id' => $order->id,
            'loyalty_point_rule_id' => $goldRule->id,
            'points_awarded' => 5,
            'points_remaining' => 5,
        ]);

        app(AwardOrderPoints::class)->handle($order->fresh());
        $this->assertSame(5, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(1, PointLog::query()->where('transaction_id', $order->id)->count());
    }

    public function test_member_does_not_fall_back_to_all_customer_program_when_no_member_program_matches(): void
    {
        $gold = $this->createTier('Gold');
        CustomerMembership::query()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'member_tier_id' => $gold->id, 'started_at' => now()->subDay(),
            'expired_at' => now()->addDays(30), 'status' => CustomerMembership::STATUS_ACTIVE,
        ]);
        $this->createRule(LoyaltyPointRule::TARGET_ALL_CUSTOMERS, 1, null, 10000, 1);

        $order = $this->createPaidCompletedOrder();

        $this->assertSame(0, (int) $this->customer->fresh()->loyalty_points);
        $this->assertDatabaseMissing('point_logs', ['transaction_id' => $order->id]);
    }

    public function test_overlapping_period_is_rejected_only_for_same_target_and_outlet(): void
    {
        $this->createRule(LoyaltyPointRule::TARGET_ALL_CUSTOMERS, 1, null, 10000, 1);
        $this->createRule(LoyaltyPointRule::TARGET_ALL_MEMBERS, 2, null, 10000, 2);

        try {
            $this->createRule(LoyaltyPointRule::TARGET_ALL_CUSTOMERS, 3, null, 20000, 1);
            $this->fail('Expected overlapping rule to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $otherBranch = Branch::factory()->for($this->tenant)->create(['code' => 'CIM']);
        $this->createRule(LoyaltyPointRule::TARGET_ALL_CUSTOMERS, 4, null, 10000, 1, $otherBranch);
        $this->assertSame(3, LoyaltyPointRule::query()->count());
    }

    public function test_expiry_removes_only_unredeemed_points_from_the_original_program_lot(): void
    {
        $rule = $this->createRule(LoyaltyPointRule::TARGET_ALL_CUSTOMERS, 1, null, 10000, 1);
        $order = $this->createPaidCompletedOrder();
        $this->assertSame(7, (int) $this->customer->fresh()->loyalty_points);

        $reward = PointReward::query()->create([
            'tenant_id' => $this->tenant->id, 'reward_name' => 'Hadiah Mini',
            'points_required' => 2, 'stock' => null, 'is_active' => true,
        ]);
        app(RedeemCustomerReward::class)->handle($this->owner, $this->customer->id, $reward->id);
        $this->assertSame(5, (int) $this->customer->fresh()->loyalty_points);

        $this->travelTo($rule->ends_at->copy()->addMinute());
        $this->artisan('loyalty:expire-points')->assertExitCode(0);

        $this->assertSame(0, (int) $this->customer->fresh()->loyalty_points);
        $this->assertDatabaseHas('loyalty_point_lots', [
            'transaction_id' => $order->id, 'points_remaining' => 0,
        ]);
        $this->assertDatabaseHas('point_logs', [
            'customer_id' => $this->customer->id, 'type' => PointLog::TYPE_EXPIRED, 'points' => 5,
        ]);
    }

    private function createPaidCompletedOrder(): Order
    {
        $order = app(CreateOrder::class)->handle(
            $this->branch, $this->cashier, $this->customer->id,
            [['service_id' => $this->service->id, 'quantity' => 10]],
        );
        app(RecordPayment::class)->handle($order, 70000, PaymentMethod::Cash, $this->cashier);
        app(ChangeOrderStatus::class)->handle($order->fresh(), OrderStatus::Ready, $this->cashier);
        app(ChangeOrderStatus::class)->handle($order->fresh(), OrderStatus::Completed, $this->cashier);

        return $order->fresh();
    }

    private function createTier(string $name): MemberTier
    {
        return MemberTier::query()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'price' => 0,
            'duration_days' => 30, 'discount_percent' => 0, 'is_active' => true,
        ]);
    }

    private function createRule(string $target, int $seed, ?int $tierId, int $amountStep, int $points, ?Branch $branch = null): LoyaltyPointRule
    {
        return LoyaltyPointRule::query()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'name' => 'Program '.$seed,
            'target_type' => $target,
            'member_tier_id' => $tierId,
            'amount_step' => $amountStep,
            'points_per_step' => $points,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
    }
}
