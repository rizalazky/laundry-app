<?php

namespace Tests\Feature;

use App\Filament\App\Resources\InventoryItems\Pages\CreateInventoryItem;
use App\Filament\App\Resources\Suppliers\Pages\CreateSupplier;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\InventoryItemPolicy;
use App\Policies\SupplierPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppliers_are_scoped_to_the_current_tenant_across_its_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $secondBranch = $tenant->branches()->create(['code' => 'DGO', 'name' => 'Dago', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        Supplier::query()->create(['tenant_id' => $tenant->id, 'name' => 'Supplier Lokal']);
        Supplier::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Supplier Rahasia']);

        $this->actingAs($owner);

        foreach ([$branch, $secondBranch] as $activeBranch) {
            $this->get('/app/'.$activeBranch->code.'/suppliers')
                ->assertOk()
                ->assertSee('Supplier Lokal')
                ->assertDontSee('Supplier Rahasia');
        }
    }

    public function test_inventory_items_are_scoped_to_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'DET-001',
            'name' => 'Detergen',
            'unit' => 'liter',
            'item_category' => 'raw_material',
            'min_stock' => 5,
        ]);
        InventoryItem::query()->create([
            'tenant_id' => $otherTenant->id,
            'sku' => 'DET-001',
            'name' => 'Detergen Tenant Lain',
            'unit' => 'liter',
            'item_category' => 'raw_material',
        ]);

        $this->actingAs($owner)
            ->get('/app/'.$branch->code.'/inventory-items')
            ->assertOk()
            ->assertSee('Detergen')
            ->assertDontSee('Detergen Tenant Lain');
    }

    public function test_owner_can_create_supplier_and_inventory_item_from_filament_forms(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'CV Bahan Bersih',
                'phone' => '08123456789',
                'address' => 'Jl. Mawar No. 1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => $tenant->id,
            'name' => 'CV Bahan Bersih',
            'phone' => '08123456789',
        ]);

        Livewire::test(CreateInventoryItem::class)
            ->fillForm([
                'sku' => 'DET-001',
                'name' => 'Detergen Cair',
                'unit' => 'liter',
                'item_category' => 'raw_material',
                'min_stock' => 5.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('inventory_items', [
            'tenant_id' => $tenant->id,
            'sku' => 'DET-001',
            'name' => 'Detergen Cair',
            'unit' => 'liter',
            'item_category' => 'raw_material',
            'min_stock' => '5.50',
        ]);
    }

    public function test_inventory_sku_is_unique_within_a_tenant_but_can_repeat_across_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = $tenant->branches()->create(['code' => 'PUSAT', 'name' => 'Pusat', 'is_active' => true]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'PLS-001',
            'name' => 'Plastik Laundry',
            'unit' => 'pack',
            'item_category' => 'raw_material',
        ]);
        InventoryItem::query()->create([
            'tenant_id' => $otherTenant->id,
            'sku' => 'PLS-001',
            'name' => 'Plastik Laundry',
            'unit' => 'pack',
            'item_category' => 'raw_material',
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateInventoryItem::class)
            ->fillForm([
                'sku' => 'PLS-001',
                'name' => 'Plastik Baru',
                'unit' => 'pack',
                'item_category' => 'raw_material',
                'min_stock' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'unique']);

        $this->assertDatabaseMissing('inventory_items', ['tenant_id' => $tenant->id, 'name' => 'Plastik Baru']);
    }

    public function test_master_data_management_is_limited_to_active_tenant_owners(): void
    {
        $tenant = Tenant::factory()->create();
        $supplier = Supplier::query()->create(['tenant_id' => $tenant->id, 'name' => 'Supplier']);
        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'HGR-001',
            'name' => 'Hanger',
            'unit' => 'pcs',
            'item_category' => 'raw_material',
        ]);

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $inactiveOwner = User::factory()->for($tenant)->withRole('tenant_owner')->create(['is_active' => false]);
        $manager = User::factory()->for($tenant)->withRole('branch_manager')->create();
        $foreignOwner = User::factory()->withRole('tenant_owner')->create();

        $supplierPolicy = new SupplierPolicy;
        $inventoryPolicy = new InventoryItemPolicy;

        $this->assertTrue($supplierPolicy->viewAny($owner));
        $this->assertTrue($supplierPolicy->update($owner, $supplier));
        $this->assertFalse($supplierPolicy->viewAny($inactiveOwner));
        $this->assertFalse($supplierPolicy->update($foreignOwner, $supplier));
        $this->assertFalse($supplierPolicy->viewAny($manager));

        $this->assertTrue($inventoryPolicy->viewAny($owner));
        $this->assertTrue($inventoryPolicy->update($owner, $item));
        $this->assertFalse($inventoryPolicy->viewAny($inactiveOwner));
        $this->assertFalse($inventoryPolicy->update($foreignOwner, $item));
        $this->assertFalse($inventoryPolicy->viewAny($manager));
    }

    public function test_inventory_item_database_constraint_rejects_duplicate_sku_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'SOAP-001',
            'name' => 'Sabun',
            'unit' => 'liter',
            'item_category' => 'raw_material',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'SOAP-001',
            'name' => 'Sabun Cadangan',
            'unit' => 'liter',
            'item_category' => 'raw_material',
        ]);
    }
}
