<?php

namespace Tests\Feature;

use App\Actions\Inventory\ManageStockTransfer;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_deducts_source_on_shipment_and_adds_destination_on_receipt(): void
    {
        [$tenant, $source, $destination, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'quantity' => '12.0000',
        ]);

        $service = app(ManageStockTransfer::class);
        $transfer = $service->request($owner, $source, $destination, [
            ['inventory_item_id' => $item->id, 'quantity' => '4.5', 'unit_cost' => '12500'],
        ]);

        $this->assertSame('requested', $transfer->status);
        $service->ship($owner, $transfer);

        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'quantity' => '7.5000',
        ]);
        $this->assertDatabaseMissing('location_stocks', [
            'branch_id' => $destination->id,
            'inventory_item_id' => $item->id,
        ]);

        $service->receive($owner, $transfer->fresh());

        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $destination->id,
            'inventory_item_id' => $item->id,
            'quantity' => '4.5000',
        ]);
        $this->assertDatabaseHas('stock_transfers', [
            'id' => $transfer->id,
            'status' => 'received',
        ]);
        $this->assertDatabaseCount('stock_movements', 2);

        $movements = StockMovement::query()->withoutGlobalScope('tenant')->orderBy('id')->get();
        $this->assertSame('-4.5000', $movements[0]->quantity);
        $this->assertSame('12.0000', $movements[0]->stock_before);
        $this->assertSame('7.5000', $movements[0]->stock_after);
        $this->assertSame('4.5000', $movements[1]->quantity);
        $this->assertSame('0.0000', $movements[1]->stock_before);
        $this->assertSame('4.5000', $movements[1]->stock_after);
    }

    public function test_insufficient_stock_prevents_shipment_without_partial_mutation(): void
    {
        [$tenant, $source, $destination, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'quantity' => '2.0000',
        ]);

        $transfer = app(ManageStockTransfer::class)->request($owner, $source, $destination, [
            ['inventory_item_id' => $item->id, 'quantity' => '5'],
        ]);

        try {
            app(ManageStockTransfer::class)->ship($owner, $transfer);
            $this->fail('Expected insufficient stock validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id, 'status' => 'requested']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_transfer_cannot_be_shipped_twice(): void
    {
        [, $source, $destination, $owner, $item] = $this->context();
        LocationStock::query()->create([
            'tenant_id' => $source->tenant_id,
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'quantity' => '10.0000',
        ]);

        $service = app(ManageStockTransfer::class);
        $transfer = $service->request($owner, $source, $destination, [
            ['inventory_item_id' => $item->id, 'quantity' => '1'],
        ]);
        $service->ship($owner, $transfer);

        $this->expectException(ValidationException::class);
        $service->ship($owner, $transfer);
    }

    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $source = Branch::factory()->for($tenant)->create();
        $destination = Branch::factory()->for($tenant)->create();
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

        return [$tenant, $source, $destination, $owner, $item];
    }
}
