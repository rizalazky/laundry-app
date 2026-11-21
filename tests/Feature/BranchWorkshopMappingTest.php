<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BranchWorkshopMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_outlet_can_be_assigned_to_active_workshops_in_its_tenant(): void
    {
        $tenant = $this->createTenant('mapping-owner');
        $outlet = $this->createBranch($tenant, 'OUT', false);
        $workshop = $this->createBranch($tenant, 'WSH', true);

        $outlet->syncWorkshops([$workshop->id]);

        $this->assertSame([$workshop->id], $outlet->fresh()->workshops->modelKeys());
        $this->assertSame([$outlet->id], $workshop->fresh()->outlets->modelKeys());

        $outlet->syncWorkshops([]);

        $this->assertCount(0, $outlet->fresh()->workshops);
    }

    public function test_outlet_cannot_be_assigned_to_inactive_or_foreign_workshops(): void
    {
        $tenant = $this->createTenant('mapping-validation');
        $otherTenant = $this->createTenant('mapping-other');
        $outlet = $this->createBranch($tenant, 'OUT', false);
        $inactiveWorkshop = $this->createBranch($tenant, 'OLD', true, false);
        $foreignWorkshop = $this->createBranch($otherTenant, 'FOREIGN', true);

        try {
            $outlet->syncWorkshops([$inactiveWorkshop->id]);
            $this->fail('An inactive workshop should not be assignable.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('outlet_workshops', 0);
        }

        $this->expectException(ValidationException::class);
        $outlet->syncWorkshops([$foreignWorkshop->id]);
    }

    public function test_database_rejects_cross_tenant_outlet_workshop_mapping(): void
    {
        $tenant = $this->createTenant('mapping-db-owner');
        $otherTenant = $this->createTenant('mapping-db-other');
        $outlet = $this->createBranch($tenant, 'OUT', false);
        $foreignWorkshop = $this->createBranch($otherTenant, 'WSH', true);

        $this->expectException(QueryException::class);

        DB::table('outlet_workshops')->insert([
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'workshop_id' => $foreignWorkshop->id,
            'created_at' => now(),
        ]);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => $slug,
            'slug' => $slug,
            'owner_email' => $slug . '@example.test',
            'plan' => 'starter',
            'max_branches' => 5,
            'is_active' => true,
        ]);
    }

    private function createBranch(Tenant $tenant, string $code, bool $isWorkshop, bool $isActive = true): Branch
    {
        return $tenant->branches()->create([
            'code' => $code,
            'name' => $code,
            'is_workshop' => $isWorkshop,
            'is_active' => $isActive,
        ]);
    }
}
