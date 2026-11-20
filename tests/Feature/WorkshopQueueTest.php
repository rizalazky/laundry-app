<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkshopQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_list_exposes_a_queue_tab_for_each_active_workshop_stage(): void
    {
        $tabs = (new ListOrders())->getTabs();

        $this->assertSame([
            'all',
            OrderStatus::Received->value,
            OrderStatus::Washing->value,
            OrderStatus::Drying->value,
            OrderStatus::Ironing->value,
            OrderStatus::Packing->value,
            OrderStatus::Ready->value,
        ], array_keys($tabs));

        $this->assertSame('all', (new ListOrders())->getDefaultActiveTab());
    }

    public function test_each_workshop_stage_tab_only_returns_orders_in_its_stage(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();

        $activeStages = [
            OrderStatus::Received,
            OrderStatus::Washing,
            OrderStatus::Drying,
            OrderStatus::Ironing,
            OrderStatus::Packing,
            OrderStatus::Ready,
        ];

        $ordersByStatus = [];

        foreach ([...$activeStages, OrderStatus::Completed, OrderStatus::Cancelled] as $status) {
            $order = Order::factory()
                ->for($branch)
                ->for($customer)
                ->create();

            $order->forceFill(['status' => $status])->save();

            $ordersByStatus[$status->value] = $order->getKey();
        }

        $tabs = (new ListOrders())->getTabs();

        foreach ($activeStages as $status) {
            $matchingOrderIds = $tabs[$status->value]
                ->modifyQuery(Order::query())
                ->pluck('id')
                ->all();

            $this->assertSame(
                [$ordersByStatus[$status->value]],
                $matchingOrderIds,
                "The [{$status->value}] tab should only include orders in that stage.",
            );
        }
    }
}
