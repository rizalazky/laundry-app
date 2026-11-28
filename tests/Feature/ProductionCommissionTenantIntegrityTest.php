<?php

namespace Tests\\Feature;

use App\\Models\\Branch;
use App\\Models\\Customer;
use App\\Models\\Order;
use App\\Models\\OrderItem;
use App\\Models\\ProductionLog;
use App\\Models\\Service;
use App\\Models\\Tenant;
use App\\Models\\User;
use Illuminate\\Database\\QueryException;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\DB;
use Tests\\TestCase;

class ProductionCommissionTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_log_cannot_reference_an_order_from_another_tenant(): void
    {
        [$tenantA, $orderA] = $this->orderContext();
        [, $orderB] = $this->orderContext();

        $this->expectException(QueryException::class);

        ProductionLog::query()->create([
            'tenant_id' => $tenantA->getKey(),
            'order_id' => $orderB->getKey(),
            'stage' => 'washer',
            'started_at' => now(),
        ]);
    }

    public function test_production_log_cannot_assign_a_worker_from_another_tenant(): void
    {
        [$tenantA, $orderA] = $this->orderContext();
        [, , $foreignWorker] = $this->orderContext();

        $this->expectException(QueryException::class);

        ProductionLog::query()->create([
            'tenant_id' => $tenantA->getKey(),
            'order_id' => $orderA->getKey(),
            'stage' => 'washer',
            'worker_id' => $foreignWorker->getKey(),
            'started_at' => now(),
        ]);
    }

    public function test_commission_log_worker_must_match_the_production_log_worker(): void
    {
        [$tenant, $order, $worker] = $this->orderContext();
        [, , $otherWorker] = $this->orderContext();
        $service = Service::factory()->for($tenant)->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'service_id' => $service->getKey(),
        ]);
        $production = ProductionLog::factory()->forOrder($order)->forWorker($worker)->create([
            'finished_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('commission_logs')->insert([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $otherWorker->getKey(),
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            'production_log_id' => $production->getKey(),
            'service_id' => $service->getKey(),
            'amount' => '100.00',
            'earned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_commission_log_item_must_belong_to_the_same_order(): void
    {
        [$tenant, $order, $worker] = $this->orderContext();
        [, $otherOrder] = $this->orderContext();
        $service = Service::factory()->for($tenant)->create();
        $item = OrderItem::factory()->create([
            'order_id' => $otherOrder->getKey(),
            'service_id' => $service->getKey(),
        ]);
        $production = ProductionLog::factory()->forOrder($order)->forWorker($worker)->create([
            'finished_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('commission_logs')->insert([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $worker->getKey(),
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            'production_log_id' => $production->getKey(),
            'service_id' => $service->getKey(),
            'amount' => '100.00',
            'earned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{Tenant, Order, User}
     */
    private function orderContext(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $worker = User::factory()->for($tenant)->withRole('operator')->create();

        $order = Order::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
        ]);

        return [$tenant, $order, $worker];
    }
}
