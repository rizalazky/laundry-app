<?php

namespace Tests\Feature;

use App\Actions\Inventory\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\LocationStock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
