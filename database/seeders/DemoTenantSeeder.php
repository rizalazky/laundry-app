<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('app.seed_demo_tenant.enabled') || app()->environment('production')) {
            return;
        }

        $ownerEmail = (string) config('app.seed_demo_tenant.owner_email');
        $ownerPassword = config('app.seed_demo_tenant.owner_password');

        if (blank($ownerEmail) || blank($ownerPassword)) {
            $this->command?->warn(
                'Demo tenant not seeded: set SEED_DEMO_TENANT_OWNER_EMAIL and SEED_DEMO_TENANT_OWNER_PASSWORD.',
            );

            return;
        }

        $tenant = Tenant::withTrashed()->firstOrCreate(
            ['slug' => config('app.seed_demo_tenant.slug')],
            [
                'name' => config('app.seed_demo_tenant.name'),
                'owner_email' => $ownerEmail,
                'plan' => 'starter',
                'max_branches' => 2,
                'subscription_ends_at' => now()->addYear(),
                'is_active' => true,
            ],
        );

        if ($tenant->trashed()) {
            $tenant->restore();
        }

        // Provision the demo tenant through the same idempotent trial flow
        // used after a real owner's email verification.
        if (app(SubscriptionService::class)->startTrial($tenant) === null) {
            throw new RuntimeException(
                'The demo tenant subscription could not be provisioned. Check the default active subscription plan.',
            );
        }

        if ($tenant->owner_email !== $ownerEmail) {
            throw new RuntimeException(
                'The configured demo owner email does not match the existing demo tenant.',
            );
        }

        $owner = User::query()->firstOrCreate(
            ['email' => $ownerEmail],
            [
                'tenant_id' => $tenant->getKey(),
                'name' => 'Demo Owner',
                'password' => $ownerPassword,
                'role' => 'tenant_owner',
                'is_active' => true,
            ],
        );

        if (
            (int) $owner->tenant_id !== (int) $tenant->getKey()
            || $owner->role !== 'tenant_owner'
        ) {
            throw new RuntimeException(
                'The configured demo owner email is already assigned to a different account scope.',
            );
        }

        $outlet = $this->firstOrCreateBranch($tenant, 'DEMO-OUTLET', 'Demo Outlet', false);
        $workshop = $this->firstOrCreateBranch($tenant, 'DEMO-WORKSHOP', 'Demo Workshop', true);

        DB::table('outlet_workshops')->insertOrIgnore([
            'tenant_id' => $tenant->getKey(),
            'outlet_id' => $outlet->getKey(),
            'workshop_id' => $workshop->getKey(),
            'created_at' => now(),
        ]);
    }

    private function firstOrCreateBranch(
        Tenant $tenant,
        string $code,
        string $name,
        bool $isWorkshop,
    ): Branch {
        $branch = Branch::withTrashed()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'code' => $code,
            ],
            [
                'name' => $name,
                'is_workshop' => $isWorkshop,
                'is_active' => true,
            ],
        );

        if ($branch->trashed()) {
            $branch->restore();
        }

        if ((bool) $branch->is_workshop !== $isWorkshop) {
            throw new RuntimeException(
                "The demo branch code {$code} already exists with a different branch type.",
            );
        }

        return $branch;
    }
}
