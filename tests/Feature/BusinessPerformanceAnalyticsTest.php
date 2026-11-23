<?php

namespace Tests\Feature;

use App\Actions\Analytics\GetBusinessPerformanceAnalytics;
use App\Enums\OrderStatus;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\CommissionLog;
use App\Models\Customer;
use App\Models\Promo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\ProductionLog;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessPerformanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_gets_outlet_service_and_employee_analytics_for_the_requested_period(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create(['name' => 'Outlet A']);
        $otherBranch = Branch::factory()->for($tenant)->create(['name' => 'Outlet B']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $worker = User::factory()->for($tenant)->withRole('operator')->create(['name' => 'Operator A']);
        $customer = \App\Models\Customer::factory()->create(['tenant_id' => $tenant->id]);
        $service = Service::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cuci Kiloan']);

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-10 09:00:00',
            'status' => OrderStatus::Completed,
            'total' => '75000.00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => 'Cuci Kiloan',
            'quantity' => '5.00',
            'subtotal' => '50000.00',
        ]);

        $otherOrder = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-11 09:00:00',
            'status' => OrderStatus::Received,
            'total' => '25000.00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $otherOrder->id,
            'service_id' => $service->id,
            'service_name' => 'Cuci Kiloan',
            'quantity' => '2.00',
            'subtotal' => '20000.00',
        ]);

        $cancelled = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-12 09:00:00',
            'status' => OrderStatus::Cancelled,
            'total' => '100000.00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $cancelled->id,
            'service_id' => $service->id,
            'service_name' => 'Cuci Kiloan',
            'quantity' => '10.00',
            'subtotal' => '100000.00',
        ]);

        ProductionLog::factory()->forOrder($order)->forWorker($worker)->create([
            'started_at' => '2026-11-10 10:00:00',
            'finished_at' => '2026-11-10 11:00:00',
            'stage' => 'washing',
        ]);
        ProductionLog::factory()->forOrder($order)->forWorker($worker)->create([
            'started_at' => '2026-11-10 11:00:00',
            'finished_at' => null,
            'stage' => 'drying',
        ]);
        Attendance::factory()->forUser($worker)->forBranch($branch)->create([
            'clock_in' => '2026-11-10 08:00:00',
        ]);

        $this->actingAs($owner);

        $analytics = app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
        );

        $this->assertSame([
            'orders' => 2,
            'completed_orders' => 1,
            'turnover' => '100000.00',
            'average_order_value' => '50000.00',
            'expenses_total' => '0.00',
            'promo_discount_total' => '0.00',
        ], $analytics['summary']);
        $this->assertCount(2, $analytics['outlets']);
        $this->assertSame('Outlet A', $analytics['outlets'][0]['branch_name']);
        $this->assertSame('75000.00', $analytics['outlets'][0]['turnover']);
        $this->assertSame([
            'service_id' => $service->id,
            'service_name' => 'Cuci Kiloan',
            'orders' => 2,
            'quantity' => '7.00',
            'gross_value' => '70000.00',
        ], $analytics['services'][0]);
        $this->assertSame([
            'user_id' => $worker->id,
            'employee_name' => 'Operator A',
            'assigned_orders' => 1,
            'production_stages' => 2,
            'completed_stages' => 1,
            'attendance_records' => 1,
            'commission_earned' => '0.00',
        ], $analytics['employees'][0]);
    }

    public function test_employee_commissions_are_reported_by_earned_date_and_selected_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $worker = User::factory()->for($tenant)->withRole('operator')->create(['name' => 'Commission Worker']);
        $customer = Customer::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create();

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-10 09:00:00',
        ]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'service_id' => $service->id]);
        $productionLog = ProductionLog::factory()->forOrder($order)->forWorker($worker)->create([
            'started_at' => '2026-10-31 23:00:00',
            'finished_at' => '2026-11-01 00:30:00',
        ]);

        CommissionLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $worker->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'production_log_id' => $productionLog->id,
            'service_id' => $service->id,
            'amount' => '450.00',
            'earned_at' => '2026-11-05 10:00:00',
        ]);

        $otherOrder = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-10 09:00:00',
        ]);
        $otherItem = OrderItem::factory()->create(['order_id' => $otherOrder->id, 'service_id' => $service->id]);
        $otherProductionLog = ProductionLog::factory()->forOrder($otherOrder)->forWorker($worker)->create([
            'started_at' => '2026-10-31 23:00:00',
            'finished_at' => '2026-11-01 00:30:00',
        ]);

        CommissionLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $worker->id,
            'order_id' => $otherOrder->id,
            'order_item_id' => $otherItem->id,
            'production_log_id' => $otherProductionLog->id,
            'service_id' => $service->id,
            'amount' => '900.00',
            'earned_at' => '2026-11-06 10:00:00',
        ]);

        $this->actingAs($owner);

        $analytics = app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
            $branch->id,
        );

        $this->assertCount(1, $analytics['employees']);
        $this->assertSame([
            'user_id' => $worker->id,
            'employee_name' => 'Commission Worker',
            'assigned_orders' => 0,
            'production_stages' => 0,
            'completed_stages' => 0,
            'attendance_records' => 0,
            'commission_earned' => '450.00',
        ], $analytics['employees'][0]);
    }

    public function test_employees_with_attendance_but_no_production_are_included(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $employee = User::factory()->for($tenant)->withRole('operator')->create(['name' => 'Attendance Only']);
        Attendance::factory()->forUser($employee)->forBranch($branch)->create([
            'clock_in' => '2026-11-12 08:00:00',
        ]);

        $this->actingAs($owner);

        $analytics = app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
            $branch->id,
        );

        $this->assertCount(1, $analytics['employees']);
        $this->assertSame([
            'user_id' => $employee->id,
            'employee_name' => 'Attendance Only',
            'assigned_orders' => 0,
            'production_stages' => 0,
            'completed_stages' => 0,
            'attendance_records' => 1,
            'commission_earned' => '0.00',
        ], $analytics['employees'][0]);
    }

    public function test_branch_manager_is_limited_to_assigned_branch_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'reports.view'],
            ['name' => 'Lihat Laporan', 'group' => 'Laporan'],
        );
        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $permission->id,
        ]);
        $customer = \App\Models\Customer::factory()->create(['tenant_id' => $tenant->id]);

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $assignedBranch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-10 09:00:00',
            'total' => '20000.00',
        ]);
        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-10 09:00:00',
            'total' => '90000.00',
        ]);

        $this->actingAs($manager);

        $analytics = app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
            $assignedBranch->id,
        );

        $this->assertSame('20000.00', $analytics['summary']['turnover']);
        $this->assertCount(1, $analytics['outlets']);
        $this->assertSame($assignedBranch->id, $analytics['outlets'][0]['branch_id']);
    }

    public function test_branch_manager_cannot_request_tenant_wide_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'reports.view'],
            ['name' => 'Lihat Laporan', 'group' => 'Laporan'],
        );
        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'branch_manager',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($manager);
        $this->expectException(DomainException::class);

        app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-30'),
        );
    }

    public function test_analytics_rejects_a_different_tenant_and_an_inverted_date_range(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        try {
            app(GetBusinessPerformanceAnalytics::class)->handle(
                $foreignTenant->id,
                CarbonImmutable::parse('2026-11-01'),
                CarbonImmutable::parse('2026-11-30'),
            );
            $this->fail('A user must not read analytics for another tenant.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Tenant', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-30'),
            CarbonImmutable::parse('2026-11-01'),
        );
    }

    public function test_unauthenticated_users_cannot_read_business_analytics(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(DomainException::class);
        app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-30'),
        );
    }

    public function test_expense_and_promotion_analytics_respect_tenant_and_branch_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $branchA = Branch::factory()->for($tenant)->create(['name' => 'Outlet A']);
        $branchB = Branch::factory()->for($tenant)->create(['name' => 'Outlet B']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = Customer::factory()->for($tenant)->create();
        $promo = Promo::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Promo November',
            'category' => Promo::CATEGORY_GENERAL,
            'reward_type' => Promo::REWARD_FIXED,
            'reward_value' => '5000.00',
            'is_active' => true,
        ]);

        $orderA = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-10 09:00:00',
            'total' => '45000.00',
            'promo_id' => $promo->id,
            'promo_name_snapshot' => 'Promo November',
            'promo_discount' => '5000.00',
        ]);

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'order_date' => '2026-11-11 09:00:00',
            'total' => '72000.00',
            'promo_id' => $promo->id,
            'promo_name_snapshot' => 'Promo November',
            'promo_discount' => '8000.00',
        ]);

        $expenseAccountId = DB::table('chart_of_accounts')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => '6100',
            'name' => 'Beban Operasional',
            'account_type' => 'expense',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            [$branchA->id, '5000.00', '2026-11-10 12:00:00'],
            [$branchB->id, '9000.00', '2026-11-11 12:00:00'],
        ] as [$locationId, $amount, $spentAt]) {
            DB::table('expenses')->insert([
                'tenant_id' => $tenant->id,
                'location_type' => 'outlet',
                'location_id' => $locationId,
                'coa_id' => $expenseAccountId,
                'amount' => $amount,
                'description' => 'Beban operasional',
                'spent_at' => $spentAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($owner);

        $allBranches = app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
        );

        $this->assertSame('14000.00', $allBranches['summary']['expenses_total']);
        $this->assertSame('13000.00', $allBranches['summary']['promo_discount_total']);
        $this->assertCount(2, $allBranches['daily_trend']);
        $this->assertSame([
            'date' => '2026-11-10',
            'revenue' => '45000.00',
            'expenses' => '5000.00',
        ], $allBranches['daily_trend'][0]);
        $this->assertSame([
            'promo_name' => 'Promo November',
            'orders' => 2,
            'discount_total' => '13000.00',
        ], $allBranches['promo_impact'][0]);

        $branchAAnalytics = app(GetBusinessPerformanceAnalytics::class)->handle(
            $tenant->id,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-11-30 23:59:59'),
            $branchA->id,
        );

        $this->assertSame('5000.00', $branchAAnalytics['summary']['expenses_total']);
        $this->assertSame('5000.00', $branchAAnalytics['summary']['promo_discount_total']);
        $this->assertCount(1, $branchAAnalytics['daily_trend']);
        $this->assertSame('45000.00', $branchAAnalytics['daily_trend'][0]['revenue']);
        $this->assertSame('5000.00', $branchAAnalytics['promo_impact'][0]['discount_total']);
    }

}
