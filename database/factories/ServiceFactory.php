<?php

namespace Database\Factories;

use App\Enums\ServiceUnit;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'unit' => ServiceUnit::Kilogram,
            'price' => fake()->numberBetween(4, 15) * 1000,
            'estimated_hours' => 48,
            'is_active' => true,
        ];
    }

    public function forUnit(ServiceUnit $unit): static
    {
        return $this->state(fn (array $attributes) => ['unit' => $unit]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
