<?php

namespace Tests\Feature;

use App\Actions\Inventory\RecordStockMovement;\nuse App\Filament\App\Resources\StockMovements\Pages\CreateStockMovement;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\LocationStock;
use App\Models\StockMovement;\nuse App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;\nuse Illuminate\Foundation\Testing\RefreshDatabase;\nuse Livewire\Livewire;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_out_and_signed_adjustment_update_the_location_balance_and_create_audit_records(): void
    {
        [$tenant, $branch, $owner, $item] = $this->makeInventoryContext();

        $service = app(RecordStockMovement::class);

        $incoming = $service->handle($branch, $owner, $item->id, StockMovementType::In, '10');
        $outgoing = $service->handle($branch, $owner, $item->id, StockMovementType::Out, '3');
        $adjustment = $service->handle($branch, $owner, $item->id, StockMovementType::Adjustment, '-2');

        $this->assertSame('10.0000', $incoming->quantity);
        $this->assertSame('0.0000', $incoming->stock_before);
        $this->assertSame('10.0000', $incoming->stock_after);

        $this->assertSame('-3.0000', $outgoing->quantity);
        $this->assertSame('10.0000', $outgoing->stock_before);
        $this->assertSame('7.0000', $outgoing->stock_after);

        $this->assertSame('-2.0000', $adjustment->quantity);
        $this->assertSame('7.0000', $adjustment->stock_before);
        $this->assertSame('5.0000', $adjustment->stock_after);

        $this->assertDatabaseHas('location_stocks', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '5.0000',
        ]);
        $this->assertSame(3, StockMovement::query()->withoutGlobalScope('tenant')->count());
        $this->assertSame($branch->id, $incoming->branch_id);
        $this->assertSame($branch->id, $outgoing->branch_id);
        $this->assertSame($branch->id, $adjustment->branch_id);
    }

    public function test_outgoing_movement_cannot_make_stock_negative_and_does_not_create_an_audit_record(): void
    {
        [, $branch, $owner, $item] = $this->makeInventoryContext();

        try {
            app(RecordStockMovement::class)->handle(
                $branch,
                $owner,
                $item->id,
                StockMovementType::Out,
                '1',
            );

            $this->fail('Expected insufficient stock validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        // The balance-row insert is part of the same transaction and must roll back.
        $this->assertDatabaseMissing('location_stocks', [
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
        ]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_movement_quantity_must_follow_base_unit_decimal_precision(): void
    {
        [, $branch, $owner, $item] = $this->makeInventoryContext(decimalPlaces: 0);

        $this->expectException(ValidationException::class);

        app(RecordStockMovement::class)->handle(
            $branch,
            $owner,
            $item->id,
            StockMovementType::In,
            '1.5',
        );
    }

    public function test_adjustment_cannot_set_stock_below_zero(): void
    {
        [, $branch, $owner, $item] = $this->makeInventoryContext();

        $this->expectException(ValidationException::class);

        app(RecordStockMovement::class)->handle(
            $branch,
            $owner,
            $item->id,
            StockMovementType::Adjustment,
            '-0.0001',
        );
    }

    public function test_supplier_purchase_converts_purchase_units_and_records_supplier_audit_details(): void
    {
        [$tenant, $branch, $owner, $item] = $this->makeInventoryContext();
        $purchaseUnit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'jerigen',
            'name' => 'Jerigen',
            'decimal_places' => 0,
            'is_active' => true,
        ]);
        $item->update([
            'purchase_unit_id' => $purchaseUnit->id,
            'purchase_unit_conversion_factor' => 5,
        ]);
        $supplier = Supplier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Bahan',
        ]);

        $movement = app(RecordStockMovement::class)->handle(
            branch: $branch,
            user: $owner,
            inventoryItemId: $item->id,
            movementType: StockMovementType::Purchase,
            quantity: '10',
            unitCost: '2000',
            supplierId: $supplier->id,
            purchaseQuantity: '2',
            purchaseUnitId: $purchaseUnit->id,
            purchaseUnitNameSnapshot: $purchaseUnit->name,
            purchaseConversionFactor: '5.000000',
        );

        $this->assertSame(StockMovementType::Purchase, $movement->movement_type);
        $this->assertSame('10.0000', $movement->quantity);
        $this->assertSame('2000.00', $movement->unit_cost);
        $this->assertSame($supplier->id, $movement->supplier_id);
        $this->assertSame('2.0000', $movement->purchase_quantity);
        $this->assertSame('Jerigen', $movement->purchase_unit_name_snapshot);
        $this->assertSame('5.000000', $movement->purchase_conversion_factor);
        $this->assertDatabaseHas('location_stocks', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '10.0000',
        ]);
    }

    public function test_usage_movement_decreases_stock_and_keeps_a_positive_input_quantity(): void
    {
        [$tenant, $branch, $owner, $item] = $this->makeInventoryContext();
        LocationStock::query()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '8.0000',
        ]);

        $movement = app(RecordStockMovement::class)->handle(
            $branch,
            $owner,
            $item->id,
            StockMovementType::Usage,
            '2.5',
        );

        $this->assertSame('-2.5000', $movement->quantity);
        $this->assertSame('5.5000', $movement->stock_after);
        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '5.5000',
        ]);
    }

    public function test_purchase_rejects_supplier_from_another_tenant(): void
    {
        [$tenant, $branch, $owner, $item] = $this->makeInventoryContext();
        $otherTenant = Tenant::factory()->create();
        $foreignSupplier = Supplier::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Supplier Asing',
        ]);

        $this->expectException(ValidationException::class);

        app(RecordStockMovement::class)->handle(
            branch: $branch,
            user: $owner,
            inventoryItemId: $item->id,
            movementType: StockMovementType::Purchase,
            quantity: '1',
            supplierId: $foreignSupplier->id,
            purchaseQuantity: '1',
            purchaseUnitId: $item->purchase_unit_id,
            purchaseUnitNameSnapshot: 'Kilogram',
            purchaseConversionFactor: '1.000000',
        );
    }

    public function test_filament_purchase_form_converts_purchase_unit_quantity_and_cost(): void
    {
        [$tenant, $branch, $owner, $item] = $this->makeInventoryContext();
        $purchaseUnit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'box',
            'name' => 'Dus',
            'decimal_places' => 0,
            'is_active' => true,
        ]);
        $item->update([
            'purchase_unit_id' => $purchaseUnit->id,
            'purchase_unit_conversion_factor' => 5,
        ]);
        $supplier = Supplier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Form Test',
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(CreateStockMovement::class)
            ->fillForm([
                'inventory_item_id' => $item->id,
                'movement_type' => StockMovementType::Purchase->value,
                'supplier_id' => $supplier->id,
                'quantity' => '2',
                'unit_cost' => '100000',
                'notes' => 'Pembelian stok',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'supplier_id' => $supplier->id,
            'movement_type' => 'purchase',
            'purchase_quantity' => '2.0000',
            'purchase_unit_id' => $purchaseUnit->id,
            'purchase_unit_name_snapshot' => 'Dus',
            'purchase_conversion_factor' => '5.000000',
            'quantity' => '10.0000',
            'unit_cost' => '20000.00',
        ]);
        $this->assertDatabaseHas('location_stocks', [
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => '10.0000',
        ]);
    }

    public function test_actor_cannot_record_movement_for_another_tenants_branch(): void
    {
        [, , $owner] = $this->makeInventoryContext();
        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->for($otherTenant)->create();
        $otherUnit = InventoryUnit::query()->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'pcs',
            'name' => 'Pieces',
            'decimal_places' => 0,
        ]);
        $otherItem = InventoryItem::query()->create([
            'tenant_id' => $otherTenant->id,
            'sku' => 'OTHER-001',
            'name' => 'Barang Tenant Lain',
            'unit' => 'Pieces',
            'base_unit_id' => $otherUnit->id,
            'purchase_unit_id' => $otherUnit->id,
            'purchase_unit_conversion_factor' => 1,
            'item_category' => 'raw_material',
        ]);

        $this->expectException(\DomainException::class);

        app(RecordStockMovement::class)->handle(
            $otherBranch,
            $owner,
            $otherItem->id,
            StockMovementType::In,
            '1',
        );
    }

    /**
     * @return array{Tenant, Branch, User, InventoryItem}
     */
    private function makeInventoryContext(int $decimalPlaces = 2): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $unit = InventoryUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'decimal_places' => $decimalPlaces,
            'is_active' => true,
        ]);

        $item = InventoryItem::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'ITEM-001',
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
