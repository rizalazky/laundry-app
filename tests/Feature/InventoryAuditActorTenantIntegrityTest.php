<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryAuditActorTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movement_creator_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, , $actorB, $itemId] = $this->context();

        $this->expectException(QueryException::class);

        DB::table('stock_movements')->insert([
            'tenant_id' => $tenantA->getKey(),
            'inventory_item_id' => $itemId,
            'movement_type' => 'in',
            'quantity' => '1.0000',
            'created_by' => $actorB->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_stock_opname_creator_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, , $actorB] = $this->context();

        $this->expectException(QueryException::class);

        $this->insertOpname($tenantA, $actorB, null);
    }

    public function test_stock_opname_finalizer_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, $actorA, $actorB] = $this->context();

        $this->expectException(QueryException::class);

        $this->insertOpname($tenantA, $actorA, $actorB);
    }

    public function test_stock_transfer_requester_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, , $actorB, , $source, $destination] = $this->context();

        $this->expectException(QueryException::class);

        $this->insertTransfer($tenantA, $actorB, null, null, $source, $destination);
    }

    public function test_stock_transfer_shipper_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, $actorA, $actorB, , $source, $destination] = $this->context();

        $this->expectException(QueryException::class);

        $this->insertTransfer($tenantA, $actorA, $actorB, null, $source, $destination);
    }

    public function test_stock_transfer_receiver_must_belong_to_the_same_tenant(): void
    {
        [$tenantA, $actorA, $actorB, , $source, $destination] = $this->context();

        $this->expectException(QueryException::class);

        $this->insertTransfer($tenantA, $actorA, null, $actorB, $source, $destination);
    }

    private function context(): array
    {
        $tenantA = Tenant::factory()->create();
        $actorA = User::factory()->for($tenantA)->withRole('tenant_owner')->create();
        $source = Branch::factory()->for($tenantA)->create();
        $destination = Branch::factory()->for($tenantA)->create();

        $tenantB = Tenant::factory()->create();
        $actorB = User::factory()->for($tenantB)->withRole('tenant_owner')->create();

        $itemId = DB::table('inventory_items')->insertGetId([
            'tenant_id' => $tenantA->getKey(),
            'sku' => 'ITEM-'.str()->upper(str()->random(8)),
            'name' => 'Detergen',
            'unit' => 'kg',
            'item_category' => 'raw_material',
            'min_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantA, $actorA, $actorB, $itemId, $source, $destination];
    }

    private function insertOpname(Tenant $tenant, User $creator, ?User $finalizer): int
    {
        return (int) DB::table('stock_opnames')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'location_type' => 'outlet',
            'location_id' => null,
            'opname_date' => now(),
            'status' => 'draft',
            'created_by' => $creator->getKey(),
            'finalized_by' => $finalizer?->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTransfer(
        Tenant $tenant,
        User $requester,
        ?User $shipper,
        ?User $receiver,
        Branch $source,
        Branch $destination,
    ): int {
        return (int) DB::table('stock_transfers')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'reference' => 'TRF-'.str()->upper(str()->random(10)),
            'transfer_type' => 'distribution',
            'source_branch_id' => $source->getKey(),
            'destination_branch_id' => $destination->getKey(),
            'status' => 'requested',
            'requested_by' => $requester->getKey(),
            'shipped_by' => $shipper?->getKey(),
            'received_by' => $receiver?->getKey(),
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
