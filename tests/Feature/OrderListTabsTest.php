<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_list_exposes_completed_and_cancelled_history_tabs(): void
    {
        $tabs = (new ListOrders())->getTabs();

        $this->assertArrayHasKey('all', $tabs);

        foreach (OrderStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $tabs);
        }
    }

    public function test_order_list_keeps_all_orders_as_the_default_tab(): void
    {
        $page = new ListOrders();

        $this->assertSame('all', $page->getDefaultActiveTab());
    }
}
