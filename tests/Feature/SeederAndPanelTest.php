<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederAndPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.seed_super_admin.password' => 'a-strong-test-password']);
    }

    public function test_seeder_creates_a_super_admin_who_can_only_access_the_admin_panel(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('role', 'super_admin')->firstOrFail();

        $this->assertNull($admin->tenant_id);
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('app')));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('role', 'super_admin')->count());
    }

    public function test_seeder_skips_the_super_admin_outside_local_without_a_password(): void
    {
        config(['app.seed_super_admin.password' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_user_factory_builds_tenant_staff_by_default_and_super_admin_on_request(): void
    {
        $cashier = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertSame('cashier', $cashier->role);
        $this->assertInstanceOf(Tenant::class, $cashier->tenant);
        $this->assertNull($superAdmin->tenant_id);
    }

    public function test_app_panel_uses_branch_as_tenant_ownership_relationship(): void
    {
        $this->assertSame('branch', Filament::getPanel('app')->getTenantOwnershipRelationshipName());
    }
}
