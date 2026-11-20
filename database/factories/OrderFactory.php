<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => Branch::query()->withoutGlobalScopes()->find($attributes['branch_id'])->tenant_id,
            'branch_id' => Branch::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'tenant_id' => Branch::query()->withoutGlobalScopes()->find($attributes['branch_id'])->tenant_id,
            ])->getKey(),
            'invoice_no' => fake()->unique()->bothify('TST-######-####'),
            'order_date' => now(),
            'due_at' => now()->addDays(2),
            'subtotal' => 50000,
            'discount' => 0,
            'total' => 50000,
            'notes' => null,
        ];
    }
}
