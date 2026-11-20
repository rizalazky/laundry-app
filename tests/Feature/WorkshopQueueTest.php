<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
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
}
