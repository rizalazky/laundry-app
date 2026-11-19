<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999),
            'owner_email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'plan' => 'starter',
            'max_branches' => 1,
            'subscription_ends_at' => now()->addDays(30),
            'is_active' => true,
        ];
    }
}
