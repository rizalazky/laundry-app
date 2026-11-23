<?php

namespace Tests\Feature;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_commission_is_snapshotted_per_service_unit(): void
    {
        [$tenant, $branch, $customer, $owner] = $this->business();
        $service = Service::factory()->for($tenant)->create([
            'price' => 10000,
            'commission_method' => 'fixed',
            'commission_value' => 1250,
        ]);

        $order = app(CreateOrderAction::class)->handle(
            branch: $branch,
            user: $owner,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 4]],
        );

        $this->assertSame('5000.00', $order->items()->sole()->commission_snapshot);
    }

    public function test_percentage_commission_uses_the_discounted_service_price(): void
    {
        [$tenant, $branch, $customer, $owner] = $this->business();
        $service = Service::factory()->for($tenant)->create([
            'price' => 10000,
            'commission_method' => 'percentage',
            'commission_value' => 10,
        ]);

        $order = app(CreateOrderAction::class)->handle(
            branch: $branch,
            user: $owner,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
            discount: 5000,
        );

        $this->assertSame('1500.00', $order->items()->sole()->commission_snapshot);
    }

    public function test_commission_is_split_equally_across_completed_production_workers_once_order_is_ready(): void
    {
        [$tenant, $branch, $customer, $owner] = $this->business();
        $service = Service::factory()->for($tenant)->create([
            'price' => 10000,
            'commission_method' => 'fixed',
            'commission_value' => 1000,
        ]);

        $order = app(CreateOrderAction::class)->handle(
            branch: $branch,
            user: $owner,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 5]],
        );

        $workers = collect(range(1, 3))->map(fn () => User::factory()
            ->for($tenant)->withRole('operator')->create());

        foreach (['washer', 'dryer', 'packing'] as $index => $stage) {
            ProductionLog::factory()->forOrder($order)->forWorker($workers[$index])->create([
                'stage' => $stage,
                'finished_at' => now(),
            ]);
        }

        app(ChangeOrderStatus::class)->handle(
            order: $order,
            to: OrderStatus::Ready,
            user: $owner,
        );

        $commissions = \App\Models\CommissionLog::query()->where('order_id', $order->id)->get();

        $this->assertCount(3, $commissions);
        $this->assertSame('5000.00', number_format((float) $commissions->sum('amount'), 2, '.', ''));
        $this->assertSame(
            ['1666.66', '1666.66', '1666.68'],
            $commissions->sortBy('production_log_id')->pluck('amount')->values()->all(),
        );

        app(\App\Actions\Commissions\AwardOrderCommissions::class)->handle($order->fresh(), now());

        $this->assertDatabaseCount('commission_logs', 3);
    }

    public function test_commission_is_not_created_for_stages_without_an_assigned_worker(): void
    {
        [$tenant, $branch, $customer, $owner] = $this->business();
        $service = Service::factory()->for($tenant)->create([
            'price' => 10000,
            'commission_method' => 'fixed',
            'commission_value' => 1000,
        ]);

        $order = app(CreateOrderAction::class)->handle(
            branch: $branch,
            user: $owner,
            customerId: $customer->id,
            items: [['service_id' => $service->id, 'quantity' => 2]],
        );

        ProductionLog::factory()->forOrder($order)->create([
            'stage' => 'washer',
            'worker_id' => null,
            'finished_at' => now(),
        ]);

        app(ChangeOrderStatus::class)->handle(
            order: $order,
            to: OrderStatus::Ready,
            user: $owner,
        );

        $this->assertDatabaseCount('commission_logs', 0);
    }

    /**
     * @return array{Tenant, Branch, Customer, User}
     */
    private function business(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $owner->branches()->attach($branch);

        return [$tenant, $branch, $customer, $owner];
    }
}
