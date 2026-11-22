<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\LocationStock;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_units_are_scoped_to_a_tenant_and_can_be_used_as_item_units(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $baseUnit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'liter',
            'name' => 'Liter',
            'decimal_places' => 3,
        ]);

        $purchaseUnit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'jerigen',
            'name' => 'Jerigen',
            'decimal_places' => 0,
        ]);

        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'DET-001',
            'name' => 'Detergen Cair',
            'unit' => 'liter',
            'base_unit_id' => $baseUnit->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'purchase_unit_conversion_factor' => 20,
            'item_category' => 'raw_material',
        ]);

        $this->assertSame($baseUnit->id, $item->baseUnit->id);
        $this->assertSame($purchaseUnit->id, $item->purchaseUnit->id);
        $this->assertSame('20.000000', $item->purchase_unit_conversion_factor);
        $this->assertSame(1, $baseUnit->baseUnitItems()->count());

        $foreignUnit = InventoryUnit::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'liter',
            'name' => 'Liter',
        ]);

        $this->assertNotSame($baseUnit->id, $foreignUnit->id);
    }

    public function test_location_stock_keeps_one_balance_per_branch_and_inventory_item(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'SOAP-001',
            'name' => 'Sabun Cair',
            'unit' => 'liter',
            'item_category' => 'raw_material',
        ]);

        $stock = LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => 12.375,
        ]);

        $this->assertSame('12.3750', $stock->fresh()->quantity);
        $this->assertSame($branch->id, $stock->branch->id);
        $this->assertSame($item->id, $stock->inventoryItem->id);

        $this->expectException(QueryException::class);

        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => 1,
        ]);
    }

    public function test_database_rejects_location_stock_that_mixes_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignItem = InventoryItem::query()->create([
            'tenant_id' => $otherTenant->id,
            'sku' => 'FOREIGN-001',
            'name' => 'Barang Tenant Lain',
            'unit' => 'pcs',
            'item_category' => 'raw_material',
        ]);

        $this->expectException(QueryException::class);

        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $foreignItem->id,
            'quantity' => 5,
        ]);
    }

    public function test_database_rejects_an_inventory_item_linked_to_a_unit_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $foreignUnit = InventoryUnit::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'pcs',
            'name' => 'Pieces',
        ]);

        $this->expectException(QueryException::class);

        InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'HGR-001',
            'name' => 'Hanger',
            'unit' => 'pcs',
            'base_unit_id' => $foreignUnit->id,
            'purchase_unit_id' => $foreignUnit->id,
            'item_category' => 'raw_material',
        ]);
    }

    public function test_location_stock_rejects_a_branch_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($otherTenant)->create();
        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'BAG-001',
            'name' => 'Laundry Bag',
            'unit' => 'pcs',
            'item_category' => 'raw_material',
        ]);

        $this->expectException(QueryException::class);

        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $foreignBranch->id,
            'inventory_item_id' => $item->id,
            'quantity' => 2,
        ]);
    }

    public function test_force_deleting_a_tenant_removes_location_stocks_before_branch_cascade(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'STOCK-001',
            'name' => 'Detergen',
            'unit' => 'liter',
            'item_category' => 'raw_material',
        ]);

        $stock = LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '15.0000',
        ]);

        $tenant->forceDelete();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
        $this->assertDatabaseMissing('location_stocks', ['id' => $stock->id]);
    }

    public function test_force_deleting_a_branch_with_location_stock_remains_restricted(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'STOCK-002',
            'name' => 'Pewangi',
            'unit' => 'liter',
            'item_category' => 'raw_material',
        ]);

        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '8.0000',
        ]);

        $this->expectException(QueryException::class);

        $branch->forceDelete();
    }
}
