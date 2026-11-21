<?php

namespace Tests\Feature;

use App\Actions\Inventory\ManageStockOpname;
use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    public function test_opname_snapshots_location_stock_and_finalization_records_signed_adjustment(): void
    {
        [$tenant, $branch, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '10.0000',
        ]);

        $service = app(ManageStockOpname::class);
        $opname = $service->create($owner, $branch, notes: 'Hitung akhir pekan');
        $line = $opname->items->sole();

        $this->assertSame('draft', $opname->status);
        $this->assertSame('outlet', $opname->location_type);
        $this->assertSame('10.0000', $line->system_qty);
        $this->assertNull($line->physical_qty);
        $this->assertSame('Detergen', $line->item_name_snapshot);

        $service->recordCounts($owner, $opname, [
            $line->id => ['physical_qty' => '8.5', 'notes' => 'Kemasan bocor'],
        ]);

        $service->finalize($owner, $opname->fresh());

        $this->assertDatabaseHas('location_stocks', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '8.5000',
        ]);
        $this->assertDatabaseHas('stock_opnames', [
            'id' => $opname->id,
            'status' => 'completed',
            'finalized_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('stock_opname_items', [
            'id' => $line->id,
            'system_qty' => '10.0000',
            'physical_qty' => '8.5000',
            'difference_qty' => '-1.5000',
        ]);

        $movement = StockMovement::query()->withoutGlobalScope('tenant')->sole();
        $this->assertSame(StockMovementType::Adjustment, $movement->movement_type);
        $this->assertSame('-1.5000', $movement->quantity);
        $this->assertSame('10.0000', $movement->stock_before);
        $this->assertSame('8.5000', $movement->stock_after);
        $this->assertStringContainsString('Stok opname #'.$opname->id, $movement->notes);
    }

    public function test_opname_cannot_be_finalized_until_every_item_has_been_counted(): void
    {
        [, $branch, $owner] = $this->context();
        $opname = app(ManageStockOpname::class)->create($owner, $branch);

        try {
            app(ManageStockOpname::class)->finalize($owner, $opname);
            $this->fail('Expected validation requiring all physical counts.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseHas('stock_opnames', ['id' => $opname->id, 'status' => 'draft']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_finalization_refuses_to_overwrite_stock_changed_after_opname_started(): void
    {
        [$tenant, $branch, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '5.0000',
        ]);

        $service = app(ManageStockOpname::class);
        $opname = $service->create($owner, $branch);
        $line = $opname->items->sole();

        app(RecordStockMovement::class)->handle(
            $branch,
            $owner,
            $item->id,
            StockMovementType::In,
            '2',
        );
        $service->recordCounts($owner, $opname, [
            $line->id => ['physical_qty' => '4'],
        ]);

        try {
            $service->finalize($owner, $opname);
            $this->fail('Expected stale stock snapshot validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '7.0000',
        ]);
        $this->assertDatabaseHas('stock_opnames', ['id' => $opname->id, 'status' => 'draft']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_soft_deleted_inventory_item_can_still_be_counted_using_its_snapshot(): void
    {
        [$tenant, $branch, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '3.0000',
        ]);

        $service = app(ManageStockOpname::class);
        $opname = $service->create($owner, $branch);
        $line = $opname->items->sole();
        $item->delete();

        $service->recordCounts($owner, $opname, [
            $line->id => ['physical_qty' => '2'],
        ]);
        $service->finalize($owner, $opname);

        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('stock_opnames', ['id' => $opname->id, 'status' => 'completed']);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'quantity' => '-1.0000',
            'movement_type' => 'adjustment',
        ]);
    }

    public function test_zero_difference_does_not_create_unnecessary_adjustment_movement(): void
    {
        [$tenant, $branch, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '3.0000',
        ]);

        $service = app(ManageStockOpname::class);
        $opname = $service->create($owner, $branch);
        $line = $opname->items->sole();
        $service->recordCounts($owner, $opname, [
            $line->id => ['physical_qty' => '3'],
        ]);
        $service->finalize($owner, $opname);

        $this->assertDatabaseHas('stock_opnames', ['id' => $opname->id, 'status' => 'completed']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_finalized_opname_cannot_be_finalized_again(): void
    {
        [$tenant, $branch, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '1.0000',
        ]);

        $service = app(ManageStockOpname::class);
        $opname = $service->create($owner, $branch);
        $line = $opname->items->sole();
        $service->recordCounts($owner, $opname, [
            $line->id => ['physical_qty' => '2'],
        ]);
        $service->finalize($owner, $opname);

        $this->expectException(ValidationException::class);
        $service->finalize($owner, $opname);
    }

    public function test_opname_rejects_a_branch_from_another_tenant(): void
    {
        [, , $owner] = $this->context();
        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->for($otherTenant)->create();

        $this->expectException(\DomainException::class);
        app(ManageStockOpname::class)->create($owner, $otherBranch);
    }

    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $unit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'CHEM-001',
            'name' => 'Detergen',
            'unit' => 'Kilogram',
            'base_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'purchase_unit_conversion_factor' => 1,
            'item_category' => 'raw_material',
            'min_stock' => 0,
        ]);

        return [$tenant, $branch, $owner, $item];
    }
}
