<?php

namespace Tests\Feature;

use App\Enums\ServiceUnit;
use App\Filament\App\Resources\Services\Pages\CreateService;
use App\Filament\App\Resources\Services\Pages\EditService;
use App\Models\Branch;
use App\Models\BranchServicePrice;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\ServicePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_are_shared_across_branches_but_never_across_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $central = Branch::factory()->for($tenant)->create(['code' => 'PUSAT']);
        $branch = Branch::factory()->for($tenant)->create(['code' => 'DGO']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        Service::factory()->for($tenant)->create(['name' => 'Cuci Kiloan Reguler']);
        Service::factory()->for($otherTenant)->create(['name' => 'Layanan Rahasia']);

        $this->actingAs($owner);

        foreach ([$central, $branch] as $activeBranch) {
            $this->get('/app/'.$activeBranch->code.'/services')
                ->assertOk()
                ->assertSee('Cuci Kiloan Reguler')
                ->assertDontSee('Layanan Rahasia');
        }
    }

    public function test_service_from_another_tenant_returns_404_on_edit_url(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignService = Service::factory()->create();

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/services/'.$foreignService->id.'/edit')
            ->assertNotFound();
    }

    public function test_only_the_owner_can_create_or_edit_services_while_other_roles_can_view(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create(['name' => 'Cuci Setrika']);

        foreach (['branch_manager', 'cashier', 'operator'] as $role) {
            $user = User::factory()->for($tenant)->withRole($role)->create();
            $user->branches()->attach($branch);

            $this->actingAs($user)
                ->get('/app/'.$branch->code.'/services')
                ->assertOk()
                ->assertSee('Cuci Setrika');

            $this->actingAs($user)
                ->get('/app/'.$branch->code.'/services/create')
                ->assertForbidden();

            $this->actingAs($user)
                ->get('/app/'.$branch->code.'/services/'.$service->id.'/edit')
                ->assertForbidden();
        }
    }

    public function test_policy_blocks_inactive_owners_and_other_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $service = Service::factory()->for($tenant)->create();
        $policy = new ServicePolicy;

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $inactiveOwner = User::factory()->for($tenant)->withRole('tenant_owner')->create(['is_active' => false]);
        $foreignOwner = User::factory()->withRole('tenant_owner')->create();

        $this->assertTrue($policy->update($owner, $service));
        $this->assertTrue($policy->delete($owner, $service));
        $this->assertFalse($policy->update($inactiveOwner, $service));
        $this->assertFalse($policy->view($foreignOwner, $service));
        $this->assertFalse($policy->update($foreignOwner, $service));
        $this->assertFalse($policy->restore($owner, $service));
        $this->assertFalse($policy->forceDelete($owner, $service));
    }

    public function test_owner_can_create_a_service_with_a_branch_price_override(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Cuci Karpet',
                'unit' => ServiceUnit::SquareMeter->value,
                'price' => 15000,
                'estimated_hours' => 72,
                'is_active' => true,
                'branchPrices' => [
                    ['branch_id' => $branch->id, 'price' => 17500],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::query()->where('name', 'Cuci Karpet')->firstOrFail();

        $this->assertSame($tenant->id, $service->tenant_id);
        $this->assertSame(ServiceUnit::SquareMeter, $service->unit);
        $this->assertSame('17500.00', $service->priceForBranch($branch));
    }

    public function test_service_name_must_be_unique_within_a_tenant_but_can_repeat_across_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        Service::factory()->for($tenant)->create(['name' => 'Cuci Kiloan']);
        Service::factory()->create(['name' => 'Setrika Saja']);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Cuci Kiloan', 'unit' => 'kg', 'price' => 7000])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Setrika Saja', 'unit' => 'kg', 'price' => 5000])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_branch_options_exclude_other_tenants_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Cuci Bedcover',
                'unit' => 'pcs',
                'price' => 30000,
                'branchPrices' => [
                    ['branch_id' => $foreignBranch->id, 'price' => 1],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseMissing('services', ['name' => 'Cuci Bedcover']);
    }

    public function test_price_falls_back_to_the_standard_price_without_a_branch_override(): void
    {
        $tenant = Tenant::factory()->create();
        $withOverride = Branch::factory()->for($tenant)->create();
        $withoutOverride = Branch::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create(['price' => 8000]);
        BranchServicePrice::factory()->create([
            'branch_id' => $withOverride->id,
            'service_id' => $service->id,
            'price' => 9500,
        ]);

        $this->assertSame('9500.00', $service->priceForBranch($withOverride));
        $this->assertSame('8000.00', $service->fresh()->priceForBranch($withoutOverride));
    }

    public function test_owner_can_update_a_service_price(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $service = Service::factory()->for($tenant)->create(['price' => 6000]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['price' => 7500])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('7500.00', $service->fresh()->price);
    }
}
