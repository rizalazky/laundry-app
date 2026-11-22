<?php

namespace Tests\Feature;

use App\Filament\App\Pages\CompanyProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_tenant_owners_can_access_company_profile(): void
    {
        $tenant = $this->createTenant('profile-owner');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $manager = $this->createUser($tenant, 'branch_manager');

        $this->actingAs($owner);
        $this->assertTrue(CompanyProfile::canAccess());

        $this->actingAs($manager);
        $this->assertFalse(CompanyProfile::canAccess());

        $owner->update(['is_active' => false]);
        $this->actingAs($owner);
        $this->assertFalse(CompanyProfile::canAccess());
    }

    public function test_tenant_owner_can_update_company_profile(): void
    {
        $tenant = $this->createTenant('profile-update');
        $owner = $this->createUser($tenant, 'tenant_owner');

        Livewire::actingAs($owner)
            ->test(CompanyProfile::class)
            ->set('companyName', 'Laundry Bersih')
            ->set('ownerEmail', 'owner@laundry.test')
            ->set('phone', '081234567890')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Laundry Bersih',
            'owner_email' => 'owner@laundry.test',
            'phone' => '081234567890',
        ]);
    }

    public function test_company_profile_rejects_duplicate_owner_email(): void
    {
        $tenant = $this->createTenant('profile-duplicate');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $otherTenant = $this->createTenant('profile-existing');

        Livewire::actingAs($owner)
            ->test(CompanyProfile::class)
            ->set('ownerEmail', $otherTenant->owner_email)
            ->call('save')
            ->assertHasErrors(['ownerEmail']);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'owner_email' => $tenant->owner_email,
        ]);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => $slug,
            'slug' => $slug,
            'owner_email' => $slug . '@example.test',
            'plan' => 'starter',
            'max_branches' => 3,
            'is_active' => true,
        ]);
    }

    private function createUser(Tenant $tenant, string $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $role,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
