<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => Order::query()->withoutGlobalScopes()->find($attributes['order_id'])->tenant_id,
            'branch_id' => fn (array $attributes) => Order::query()->withoutGlobalScopes()->find($attributes['order_id'])->branch_id,
            'order_id' => Order::factory(),
            'type' => PaymentType::Payment,
            'method' => PaymentMethod::Cash,
            'amount' => 10000,
            'paid_at' => now(),
        ];
    }
}
