<?php

namespace Tests\\Feature;

use App\\Filament\\Auth\\EditUserProfile;
use App\\Models\\Tenant;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Livewire\\Livewire;
use Tests\\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owner_can_update_company_fields_inside_native_profile(): void
    {
        $tenant = $this->createTenant('profile-owner');
        $owner = $this->createUser($tenant, 'tenant_owner');

        Livewire::actingAs($owner)
            ->test(EditUserProfile::class)
            ->assertSet('data.tenant.name', 'profile-owner')
            ->set('data.tenant.name', 'Laundry Bersih')
            ->set('data.tenant.owner_email', 'owner@laundry.test')
            ->set('data.tenant.phone', '081234567890')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Laundry Bersih',
            'owner_email' => 'owner@laundry.test',
            'phone' => '081234567890',
        ]);
    }

    public function test_native_profile_rejects_duplicate_company_owner_email(): void
    {
        $tenant = $this->createTenant('profile-duplicate');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $otherTenant = $this->createTenant('profile-existing');

        Livewire::actingAs($owner)
            ->test(EditUserProfile::class)
            ->set('data.tenant.owner_email', $otherTenant->owner_email)
            ->call('save')
            ->assertHasErrors(['data.tenant.owner_email']);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'owner_email' => $tenant->owner_email,
        ]);
    }

    public function test_non_owner_cannot_change_tenant_company_fields_through_profile_state(): void
    {
        $tenant = $this->createTenant('profile-manager');
        $manager = $this->createUser($tenant, 'branch_manager');

        Livewire::actingAs($manager)
            ->test(EditUserProfile::class)
            ->set('data.tenant.name', 'Unauthorized Change')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'profile-manager',
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
