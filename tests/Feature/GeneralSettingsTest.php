<?php

namespace Tests\Feature;

use App\Filament\App\Pages\GeneralSettings;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_tenants_receive_default_general_settings(): void
    {
        $tenant = $this->createTenant('settings-defaults');

        $this->assertSame('Asia/Jakarta', $tenant->getTimezone());
        $this->assertSame('d/m/Y', $tenant->getPreferredDateFormat());
        $this->assertNull($tenant->getInvoicePrefix());
        $this->assertSame(24, $tenant->getDefaultEstimationHours());
        $this->assertTrue($tenant->allowsPartialPayment());
    }

    public function test_display_date_format_does_not_override_eloquent_database_date_format(): void
    {
        $tenant = $this->createTenant('settings-date-format');

        $this->assertSame('Y-m-d H:i:s', $tenant->getDateFormat());
        $this->assertSame('d/m/Y', $tenant->getPreferredDateFormat());
    }

    public function test_tenant_owner_can_update_general_settings(): void
    {
        $tenant = $this->createTenant('settings-update');
        $owner = $this->createUser($tenant, 'tenant_owner');

        Livewire::actingAs($owner)
            ->test(GeneralSettings::class)
            ->set('timezone', 'Asia/Makassar')
            ->set('dateFormat', 'Y-m-d')
            ->set('invoicePrefix', 'laundry01')
            ->set('defaultEstimationHours', 36)
            ->set('allowPartialPayment', false)
            ->call('save')
            ->assertHasNoErrors();

        $tenant->refresh();

        $this->assertSame('Asia/Makassar', $tenant->getTimezone());
        $this->assertSame('Y-m-d', $tenant->getPreferredDateFormat());
        $this->assertSame('LAUNDRY01', $tenant->getInvoicePrefix());
        $this->assertSame(36, $tenant->getDefaultEstimationHours());
        $this->assertFalse($tenant->allowsPartialPayment());
    }

    public function test_read_only_tenant_owner_cannot_update_general_settings(): void
    {
        $tenant = $this->createTenant('settings-read-only');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $plan = Plan::query()->create([
            'code' => 'settings-read-only-plan',
            'name' => 'Read Only Plan',
            'monthly_price' => 99000,
            'is_active' => true,
        ]);

        Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);

        Livewire::actingAs($owner)
            ->test(GeneralSettings::class)
            ->set('timezone', 'Asia/Makassar')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Asia/Jakarta', $tenant->fresh()->getTimezone());
    }

    public function test_general_settings_are_isolated_between_tenants(): void
    {
        $tenantA = $this->createTenant('settings-a');
        $tenantB = $this->createTenant('settings-b');
        $ownerA = $this->createUser($tenantA, 'tenant_owner');

        Livewire::actingAs($ownerA)
            ->test(GeneralSettings::class)
            ->set('timezone', 'Asia/Makassar')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Asia/Makassar', $tenantA->fresh()->getTimezone());
        $this->assertSame('Asia/Jakarta', $tenantB->fresh()->getTimezone());
    }

    public function test_only_active_tenant_owners_can_access_general_settings(): void
    {
        $tenant = $this->createTenant('settings-access');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $staff = $this->createUser($tenant, 'branch_manager');

        $this->actingAs($owner);
        $this->assertTrue(GeneralSettings::canAccess());

        $this->actingAs($staff);
        $this->assertFalse(GeneralSettings::canAccess());
    }

    public function test_invalid_general_settings_are_rejected(): void
    {
        $tenant = $this->createTenant('settings-invalid');
        $owner = $this->createUser($tenant, 'tenant_owner');

        Livewire::actingAs($owner)
            ->test(GeneralSettings::class)
            ->set('timezone', 'Not/A_Timezone')
            ->set('dateFormat', 'invalid')
            ->set('defaultEstimationHours', 0)
            ->call('save')
            ->assertHasErrors(['timezone', 'dateFormat', 'defaultEstimationHours']);

        $this->assertSame('Asia/Jakarta', $tenant->fresh()->getTimezone());
    }

    public function test_updating_settings_preserves_unknown_json_keys(): void
    {
        $tenant = $this->createTenant('settings-preserve');
        $tenant->forceFill(['general_settings' => ['future_setting' => 'keep-me']])->save();

        $tenant->updateGeneralSettings(['timezone' => 'Asia/Makassar']);

        $this->assertSame('keep-me', $tenant->fresh()->generalSettings()['future_setting']);
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

    public function test_general_settings_rechecks_owner_access_on_every_livewire_request(): void
    {
        $tenant = $this->createTenant('settings-livewire-access');
        $owner = $this->createUser($tenant, 'tenant_owner');

        $component = Livewire::actingAs($owner)->test(GeneralSettings::class);

        $owner->update(['is_active' => false]);

        $component->set('timezone', 'Asia/Makassar')->assertForbidden();

        $this->assertSame('Asia/Jakarta', $tenant->fresh()->getTimezone());
    }

    public function test_general_settings_rejects_a_stale_owner_deactivated_in_the_database(): void
    {
        $tenant = $this->createTenant('settings-stale-owner');
        $owner = $this->createUser($tenant, 'tenant_owner');

        $component = Livewire::actingAs($owner)->test(GeneralSettings::class);

        User::query()->whereKey($owner->getKey())->update(['is_active' => false]);

        $component->set('timezone', 'Asia/Makassar')->assertForbidden();

        $this->assertSame('Asia/Jakarta', $tenant->fresh()->getTimezone());
    }

}
