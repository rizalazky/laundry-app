<?php

namespace Tests\Feature;

use App\Actions\Dashboard\GetDashboardFinancialSummary;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFinancialSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_sees_monthly_turnover_net_payments_and_outstanding_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_date' => now(),
            'status' => OrderStatus::Received,
            'total' => '50000.00',
            'paid_amount' => '30000.00',
        ]);

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_date' => now(),
            'status' => OrderStatus::Cancelled,
            'total' => '10000.00',
            'paid_amount' => '0.00',
        ]);

        Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'type' => PaymentType::Payment,
            'amount' => '30000.00',
            'paid_at' => now(),
        ]);

        Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'type' => PaymentType::Refund,
            'amount' => '5000.00',
            'paid_at' => now(),
        ]);

        $summary = app(GetDashboardFinancialSummary::class)->handle($owner);

        $this->assertSame('50000.00', $summary['monthly_turnover']);
        $this->assertSame('25000.00', $summary['monthly_net_payments']);
        $this->assertSame('20000.00', $summary['outstanding_balance']);
    }

    public function test_branch_manager_only_sees_financial_data_for_assigned_active_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $assignedBranch = Branch::factory()->for($tenant)->create();
        $otherBranch = Branch::factory()->for($tenant)->create();
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $manager->branches()->attach($assignedBranch);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $assignedBranch->id,
            'customer_id' => $customer->id,
            'order_date' => now(),
            'status' => OrderStatus::Received,
            'total' => '20000.00',
            'paid_amount' => '0.00',
        ]);

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'customer_id' => $customer->id,
            'order_date' => now(),
            'status' => OrderStatus::Received,
            'total' => '90000.00',
            'paid_amount' => '0.00',
        ]);

        $summary = app(GetDashboardFinancialSummary::class)->handle($manager);

        $this->assertSame('20000.00', $summary['monthly_turnover']);
        $this->assertSame('0.00', $summary['monthly_net_payments']);
        $this->assertSame('20000.00', $summary['outstanding_balance']);
    }

    public function test_staff_without_an_assigned_active_branch_gets_an_empty_summary(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();

        $summary = app(GetDashboardFinancialSummary::class)->handle($cashier);

        $this->assertSame([
            'monthly_turnover' => '0.00',
            'monthly_net_payments' => '0.00',
            'outstanding_balance' => '0.00',
        ], $summary);
    }

    public function test_inactive_or_unassigned_user_gets_an_empty_summary(): void
    {
        $inactive = User::factory()->withRole('tenant_owner')->create([
            'is_active' => false,
        ]);

        $unassigned = User::factory()->superAdmin()->create();

        $action = app(GetDashboardFinancialSummary::class);

        $this->assertSame('0.00', $action->handle($inactive)['monthly_turnover']);
        $this->assertSame('0.00', $action->handle($unassigned)['monthly_turnover']);
    }

    public function test_financial_summary_reloads_actor_and_returns_empty_after_deactivation(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        User::query()->whereKey($owner->getKey())->update(['is_active' => false]);

        $summary = app(GetDashboardFinancialSummary::class)->handle($owner);

        $this->assertSame([
            'monthly_turnover' => '0.00',
            'monthly_net_payments' => '0.00',
            'outstanding_balance' => '0.00',
        ], $summary);
    }

}
