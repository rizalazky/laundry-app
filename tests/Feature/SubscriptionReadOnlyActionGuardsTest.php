<?php

namespace Tests\Feature;

use App\Actions\Affiliates\RegisterAffiliateReferral;
use App\Actions\Affiliates\UpdateAffiliatePayoutStatus;
use App\Actions\Attendance\ClockIn;
use App\Actions\Attendance\ClockOut;
use App\Actions\CourierTasks\UpdateCourierTaskStatus;
use App\Actions\Loyalty\AwardCustomerPoints;
use App\Actions\Loyalty\RedeemCustomerReward;
use App\Enums\CourierTaskStatus;
use App\Enums\CourierTaskType;
use App\Enums\OrderStatus;
use App\Models\Attendance;
use App\Models\AffiliatePayout;
use App\Models\Branch;
use App\Models\CourierTask;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PointLog;
use App\Models\PointReward;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionReadOnlyActionGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_tenant_cannot_award_or_redeem_loyalty_points(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = Customer::factory()->for($tenant)->create(['loyalty_points' => 100]);
        $reward = PointReward::query()->create([
            'tenant_id' => $tenant->id,
            'reward_name' => 'Voucher',
            'points_required' => 50,
            'stock' => 3,
            'is_active' => true,
        ]);
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();
        $this->actingAs($owner);

        try {
            app(AwardCustomerPoints::class)->handle($owner, (int) $customer->id, 10);
            $this->fail('A read-only tenant must not award loyalty points.');
        } catch (AuthorizationException) {
            $this->assertSame(100, (int) $customer->fresh()->loyalty_points);
            $this->assertDatabaseCount('point_logs', 0);
        }

        try {
            app(RedeemCustomerReward::class)->handle($owner, (int) $customer->id, (int) $reward->id);
            $this->fail('A read-only tenant must not redeem loyalty rewards.');
        } catch (AuthorizationException) {
            $this->assertSame(100, (int) $customer->fresh()->loyalty_points);
            $this->assertSame('3.00', $reward->fresh()->stock);
            $this->assertDatabaseCount('point_logs', 0);
        }
    }

    public function test_read_only_tenant_cannot_mark_affiliate_payout_paid(): void
    {
        $tenant = Tenant::factory()->create();
        $referred = Tenant::factory()->create();
        $referral = app(RegisterAffiliateReferral::class)->handle($tenant->id, $referred->id, 10);
        $payout = $referral->payouts()->create([
            'tenant_id' => $tenant->id,
            'amount' => '125000.00',
            'payout_date' => now()->toDateString(),
            'status' => AffiliatePayout::STATUS_PENDING,
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $this->expectException(AuthorizationException::class);
        app(UpdateAffiliatePayoutStatus::class)->handle($owner, (int) $payout->id, AffiliatePayout::STATUS_PAID);
    }

    public function test_read_only_tenant_cannot_update_courier_task_status(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::Received,
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $courier = User::factory()->for($tenant)->withRole('courier')->create();
        $courier->branches()->attach($branch);
        $task = CourierTask::query()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'created_by' => $owner->id,
            'task_type' => CourierTaskType::Pickup,
            'address' => 'Jl. Mawar No. 1',
            'status' => CourierTaskStatus::Assigned,
        ]);
        $this->grantPermission($tenant, 'courier', 'courier_tasks.update');
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        $this->expectException(AuthorizationException::class);
        app(UpdateCourierTaskStatus::class)->handle($task, CourierTaskStatus::InProgress, $courier);
    }

    public function test_read_only_tenant_cannot_clock_in_or_clock_out(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $user = User::factory()->for($tenant)->withRole('cashier')->create();
        $user->branches()->attach($branch);
        $this->actingAs($user);

        $attendance = app(ClockIn::class)->handle($user, $branch);
        $tenant->forceFill(['subscription_ends_at' => now()->subDay()])->save();

        try {
            app(ClockOut::class)->handle($user, $branch);
            $this->fail('A read-only tenant must not clock out.');
        } catch (AuthorizationException) {
            $this->assertNull($attendance->fresh()->clock_out);
        }

        try {
            app(ClockIn::class)->handle($user, $branch);
            $this->fail('A read-only tenant must not clock in.');
        } catch (AuthorizationException) {
            $this->assertSame(1, Attendance::query()->where('user_id', $user->id)->count());
        }
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Operasional'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
