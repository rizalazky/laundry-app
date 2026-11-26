<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The first super admin is created from SEED_SUPER_ADMIN_EMAIL and
     * SEED_SUPER_ADMIN_PASSWORD. A weak default password is only allowed locally.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            SubscriptionPlanSeeder::class,
            DemoTenantSeeder::class,
        ]);

        $email = config('app.seed_super_admin.email');
        $password = config('app.seed_super_admin.password') ?: (app()->isLocal() ? 'password' : null);

        if (blank($password)) {
            $this->command?->warn('Super Admin not seeded: set SEED_SUPER_ADMIN_PASSWORD.');

            return;
        }

        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => $password,
                'role' => 'super_admin',
                'tenant_id' => null,
                'is_active' => true,
            ],
        );
    }
}
