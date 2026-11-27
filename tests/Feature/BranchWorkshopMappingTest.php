<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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


    public function test_authenticated_owner_can_update_workshop_mapping_within_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $outlet = $this->createBranch($tenant, 'OWN-OUT', false);
        $workshop = $this->createBranch($tenant, 'OWN-WSH', true);

        $this->actingAs($owner);

        $outlet->syncWorkshops([$workshop->id]);

        $this->assertDatabaseHas('outlet_workshops', [
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'workshop_id' => $workshop->id,
        ]);
    }

    public function test_authenticated_owner_cannot_change_workshop_mapping_for_a_foreign_tenant_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignOutlet = $this->createBranch($foreignTenant, 'FOREIGN-OUT', false);
        $foreignWorkshop = $this->createBranch($foreignTenant, 'FOREIGN-WSH', true);

        // Seed a valid mapping through the trusted internal path before authenticating.
        $foreignOutlet->syncWorkshops([$foreignWorkshop->id]);

        $this->actingAs($owner);

        try {
            $foreignOutlet->syncWorkshops([]);
            $this->fail('An owner must not mutate workshop mappings for another tenant.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'Anda tidak memiliki akses untuk mengubah mapping workshop cabang ini.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('outlet_workshops', [
            'tenant_id' => $foreignTenant->id,
            'outlet_id' => $foreignOutlet->id,
            'workshop_id' => $foreignWorkshop->id,
        ]);
    }

    public function test_inactive_tenant_owner_cannot_change_workshop_mappings_directly(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create(['is_active' => false]);
        $outlet = $this->createBranch($tenant, 'OUT-INACTIVE-OWNER', false);
        $workshop = $this->createBranch($tenant, 'WSH-INACTIVE-OWNER', true);
        $outlet->syncWorkshops([$workshop->id]);

        $this->actingAs($owner);

        $this->expectException(AuthorizationException::class);
        $outlet->syncWorkshops([]);
    }
}
