<?php

namespace Tests\Feature;

use App\Filament\App\Resources\InventoryItems\Schemas\InventoryItemForm;
use App\Filament\App\Resources\InventoryUnits\Pages\CreateInventoryUnit;
use App\Models\InventoryUnit;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryUnitManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_units_are_scoped_to_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'liter',
            'name' => 'Liter',
            'decimal_places' => 3,
            'is_active' => true,
        ]);
        InventoryUnit::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'liter',
            'name' => 'Liter Tenant Lain',
            'decimal_places' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/inventory-units')
            ->assertOk()
            ->assertSee('Liter')
            ->assertDontSee('Liter Tenant Lain');
    }

    public function test_tenant_owner_can_create_an_inventory_unit(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateInventoryUnit::class)
            ->fillForm([
                'code' => 'kg',
                'name' => 'Kilogram',
                'decimal_places' => 3,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('inventory_units', [
            'tenant_id' => $tenant->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'decimal_places' => 3,
            'is_active' => true,
        ]);
    }

    public function test_new_inventory_items_only_offer_active_units_from_their_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $activeUnit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'liter',
            'name' => 'Liter',
            'decimal_places' => 3,
            'is_active' => true,
        ]);
        $inactiveUnit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'pail',
            'name' => 'Pail',
            'decimal_places' => 0,
            'is_active' => false,
        ]);
        $foreignUnit = InventoryUnit::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'decimal_places' => 3,
            'is_active' => true,
        ]);

        $this->assertSame(
            [$activeUnit->id => 'Liter (liter)'],
            InventoryItemForm::unitOptions($tenant->id, null)
        );

        $this->assertSame(
            [
                $activeUnit->id => 'Liter (liter)',
                $inactiveUnit->id => 'Pail (pail) (Nonaktif)',
            ],
            InventoryItemForm::unitOptions($tenant->id, $inactiveUnit->id)
        );

        $this->assertArrayNotHasKey(
            $foreignUnit->id,
            InventoryItemForm::unitOptions($tenant->id, null)
        );
    }
}
