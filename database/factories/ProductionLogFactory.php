<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionLog>
 */
class ProductionLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'tenant_id' => fn (array $attributes): int => (int) Order::query()
                ->withoutGlobalScopes()
                ->findOrFail($attributes['order_id'])
                ->tenant_id,
            'stage' => 'washer',
            'worker_id' => null,
            'machine_code' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->getKey(),
        ]);
    }

    public function forWorker(User $worker): static
    {
        return $this->state(fn (): array => [
            'worker_id' => $worker->getKey(),
        ]);
    }
}
