<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'tenant_id' => fn (array $attributes): int => (int) Branch::query()
                ->withoutGlobalScopes()
                ->findOrFail($attributes['branch_id'])
                ->tenant_id,
            'user_id' => fn (array $attributes): int => User::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->getKey(),
            'clock_in' => now()->startOfDay()->addHours(8),
            'clock_out' => null,
            'status' => 'present',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->getKey(),
        ]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->getKey(),
        ]);
    }
}
