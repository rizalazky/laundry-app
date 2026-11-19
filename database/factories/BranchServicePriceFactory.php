<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchServicePrice;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchServicePrice>
 */
class BranchServicePriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'service_id' => Service::factory(),
            'price' => fake()->numberBetween(4, 15) * 1000,
        ];
    }
}
