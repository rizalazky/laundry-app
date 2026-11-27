<?php

namespace Tests\Feature;

use App\Actions\Loyalty\AwardCustomerPoints;
use App\Actions\Loyalty\RedeemCustomerReward;
use App\Models\Customer;
use App\Models\PointLog;
use App\Models\PointReward;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_award_points_and_customer_balance_is_updated_with_a_ledger_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Loyal', 'loyalty_points' => 25]);

        $this->actingAs($owner);

        $log = app(AwardCustomerPoints::class)->handle($owner, $customer->id, 40, 'Bonus pembukaan');

        $this->assertSame(PointLog::TYPE_EARN, $log->type);
        $this->assertSame(40, $log->points);
        $this->assertSame(65, (int) $customer->fresh()->loyalty_points);
        $this->assertDatabaseHas('point_logs', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => PointLog::TYPE_EARN,
            'points' => 40,
            'description' => 'Bonus pembukaan',
        ]);
    }

    public function test_reward_redemption_debits_points_and_stock_atomically(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Tukar', 'loyalty_points' => 150]);
        $reward = PointReward::query()->create([
            'tenant_id' => $tenant->id,
            'reward_name' => 'Gratis Cuci 5 Kg',
            'points_required' => 100,
            'stock' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($owner);

        $log = app(RedeemCustomerReward::class)->handle($owner, $customer->id, $reward->id);

        $this->assertSame(PointLog::TYPE_REDEEM, $log->type);
        $this->assertSame(100, $log->points);
        $this->assertSame(50, (int) $customer->fresh()->loyalty_points);
        $this->assertSame('1.00', $reward->fresh()->stock);
        $this->assertDatabaseHas('point_logs', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => PointLog::TYPE_REDEEM,
            'points' => 100,
        ]);
    }

    public function test_reward_redemption_rejects_insufficient_points_and_empty_stock_without_mutation(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan', 'loyalty_points' => 20]);
        $reward = PointReward::query()->create([
            'tenant_id' => $tenant->id,
            'reward_name' => 'Voucher Cuci',
            'points_required' => 50,
            'stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($owner);

        try {
            app(RedeemCustomerReward::class)->handle($owner, $customer->id, $reward->id);
            $this->fail('Expected insufficient points validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $customer->forceFill(['loyalty_points' => 100])->save();

        try {
            app(RedeemCustomerReward::class)->handle($owner, $customer->id, $reward->id);
            $this->fail('Expected empty stock validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('point_reward_id', $exception->errors());
        }

        $this->assertSame(100, (int) $customer->fresh()->loyalty_points);
        $this->assertSame('0.00', $reward->fresh()->stock);
        $this->assertDatabaseCount('point_logs', 0);
    }

    public function test_loyalty_actions_reject_cross_tenant_records_and_unauthorized_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignCustomer = $otherTenant->customers()->create(['name' => 'Pelanggan Tenant Lain']);
        $foreignReward = PointReward::query()->create([
            'tenant_id' => $otherTenant->id,
            'reward_name' => 'Hadiah Rahasia',
            'points_required' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($owner);

        try {
            app(AwardCustomerPoints::class)->handle($owner, $foreignCustomer->id, 10);
            $this->fail('Expected cross-tenant customer validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $localCustomer = $tenant->customers()->create(['name' => 'Pelanggan Lokal', 'loyalty_points' => 100]);

        try {
            app(RedeemCustomerReward::class)->handle($owner, $localCustomer->id, $foreignReward->id);
            $this->fail('Expected cross-tenant reward validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('point_reward_id', $exception->errors());
        }

        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $this->expectException(AuthorizationException::class);
        app(AwardCustomerPoints::class)->handle($cashier, $localCustomer->id, 10);
    }

    public function test_loyalty_actions_reject_a_foreign_tenant_owner_as_the_supplied_actor(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        $foreignCustomer = $foreignTenant->customers()->create([
            'name' => 'Pelanggan Tenant Lain',
            'loyalty_points' => 100,
        ]);
        $foreignReward = PointReward::query()->create([
            'tenant_id' => $foreignTenant->id,
            'reward_name' => 'Hadiah Tenant Lain',
            'points_required' => 50,
            'stock' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($owner);

        try {
            app(AwardCustomerPoints::class)->handle($foreignOwner, $foreignCustomer->id, 10);
            $this->fail('A caller must not supply another tenant owner as the loyalty action actor.');
        } catch (AuthorizationException) {
            $this->assertSame(100, (int) $foreignCustomer->fresh()->loyalty_points);
        }

        try {
            app(RedeemCustomerReward::class)->handle($foreignOwner, $foreignCustomer->id, $foreignReward->id);
            $this->fail('A caller must not redeem rewards using another tenant owner as the action actor.');
        } catch (AuthorizationException) {
            $this->assertSame(100, (int) $foreignCustomer->fresh()->loyalty_points);
            $this->assertSame('2.00', $foreignReward->fresh()->stock);
        }

        $this->assertDatabaseCount('point_logs', 0);
    }
}
