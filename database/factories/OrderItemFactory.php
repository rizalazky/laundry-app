<?php

namespace Database\Factories;

use App\Enums\ServiceUnit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'service_id' => Service::factory(),
            'service_name' => 'Cuci Kiloan',
            'unit' => ServiceUnit::Kilogram,
            'quantity' => 5,
            'unit_price' => 10000,
            'subtotal' => 50000,
        ];
    }
}
