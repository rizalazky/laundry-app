<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_permissions_separate_read_and_management_access(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('cashier')->create();
        $item = new InventoryItem(['tenant_id' => $tenant->id]);
        $unit = new InventoryUnit(['tenant_id' => $tenant->id]);

        $this->assertFalse($user->can('viewAny', InventoryItem::class));
        $this->assertFalse($user->can('viewAny', InventoryUnit::class));

        $this->grantPermission($tenant, 'cashier', 'inventory.view', 'Inventori');

        $this->assertTrue($user->can('viewAny', InventoryItem::class));
        $this->assertTrue($user->can('view', $item));
        $this->assertTrue($user->can('viewAny', InventoryUnit::class));
        $this->assertTrue($user->can('view', $unit));
        $this->assertFalse($user->can('create', InventoryItem::class));
        $this->assertFalse($user->can('create', InventoryUnit::class));

        $this->grantPermission($tenant, 'cashier', 'inventory.manage', 'Inventori');

        $this->assertTrue($user->can('create', InventoryItem::class));
        $this->assertTrue($user->can('update', $item));
        $this->assertTrue($user->can('create', InventoryUnit::class));
        $this->assertTrue($user->can('update', $unit));
        $this->assertFalse($user->can('delete', $unit));
    }

    public function test_supplier_permissions_separate_read_and_management_access(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('operator')->create();
        $supplier = new Supplier(['tenant_id' => $tenant->id]);

        $this->assertFalse($user->can('viewAny', Supplier::class));

        $this->grantPermission($tenant, 'operator', 'suppliers.view', 'Inventori');

        $this->assertTrue($user->can('viewAny', Supplier::class));
        $this->assertTrue($user->can('view', $supplier));
        $this->assertFalse($user->can('create', Supplier::class));
        $this->assertFalse($user->can('update', $supplier));

        $this->grantPermission($tenant, 'operator', 'suppliers.manage', 'Inventori');

        $this->assertTrue($user->can('create', Supplier::class));
        $this->assertTrue($user->can('update', $supplier));
        $this->assertTrue($user->can('delete', $supplier));
    }

    public function test_stock_permissions_cover_movements_opnames_and_transfers(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('cashier')->create();
        $movement = new StockMovement(['tenant_id' => $tenant->id]);
        $opname = new StockOpname(['tenant_id' => $tenant->id]);
        $transfer = new StockTransfer(['tenant_id' => $tenant->id]);

        $this->assertFalse($user->can('viewAny', StockMovement::class));
        $this->assertFalse($user->can('viewAny', StockOpname::class));
        $this->assertFalse($user->can('viewAny', StockTransfer::class));

        $this->grantPermission($tenant, 'cashier', 'stock.view', 'Inventori');

        $this->assertTrue($user->can('viewAny', StockMovement::class));
        $this->assertTrue($user->can('view', $movement));
        $this->assertTrue($user->can('viewAny', StockOpname::class));
        $this->assertTrue($user->can('view', $opname));
        $this->assertTrue($user->can('viewAny', StockTransfer::class));
        $this->assertTrue($user->can('view', $transfer));
        $this->assertFalse($user->can('create', StockMovement::class));
        $this->assertFalse($user->can('create', StockOpname::class));
        $this->assertFalse($user->can('create', StockTransfer::class));

        $this->grantPermission($tenant, 'cashier', 'stock.manage', 'Inventori');

        $this->assertTrue($user->can('create', StockMovement::class));
        $this->assertTrue($user->can('create', StockOpname::class));
        $this->assertTrue($user->can('create', StockTransfer::class));
        $this->assertFalse($user->can('update', $movement));
        $this->assertFalse($user->can('delete', $movement));
        $this->assertFalse($user->can('update', $opname));
        $this->assertFalse($user->can('update', $transfer));
    }

    public function test_inventory_supplier_and_stock_permissions_are_tenant_scoped(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('branch_manager')->create();

        $this->grantPermission($otherTenant, 'branch_manager', 'inventory.view', 'Inventori');
        $this->grantPermission($otherTenant, 'branch_manager', 'suppliers.view', 'Inventori');
        $this->grantPermission($otherTenant, 'branch_manager', 'stock.view', 'Inventori');

        $this->assertFalse($user->can('viewAny', InventoryItem::class));
        $this->assertFalse($user->can('viewAny', Supplier::class));
        $this->assertFalse($user->can('viewAny', StockMovement::class));

        $this->grantPermission($tenant, 'branch_manager', 'inventory.view', 'Inventori');
        $this->grantPermission($tenant, 'branch_manager', 'suppliers.view', 'Inventori');
        $this->grantPermission($tenant, 'branch_manager', 'stock.view', 'Inventori');

        $foreignItem = new InventoryItem(['tenant_id' => $otherTenant->id]);
        $foreignSupplier = new Supplier(['tenant_id' => $otherTenant->id]);
        $foreignMovement = new StockMovement(['tenant_id' => $otherTenant->id]);

        $this->assertFalse($user->can('view', $foreignItem));
        $this->assertFalse($user->can('view', $foreignSupplier));
        $this->assertFalse($user->can('view', $foreignMovement));
    }

    public function test_inactive_staff_is_denied_even_when_permissions_are_assigned(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->withRole('cashier')->create([
            'is_active' => false,
        ]);

        $this->grantPermission($tenant, 'cashier', 'inventory.view', 'Inventori');
        $this->grantPermission($tenant, 'cashier', 'inventory.manage', 'Inventori');
        $this->grantPermission($tenant, 'cashier', 'suppliers.view', 'Inventori');
        $this->grantPermission($tenant, 'cashier', 'suppliers.manage', 'Inventori');
        $this->grantPermission($tenant, 'cashier', 'stock.view', 'Inventori');
        $this->grantPermission($tenant, 'cashier', 'stock.manage', 'Inventori');

        $this->assertFalse($user->can('viewAny', InventoryItem::class));
        $this->assertFalse($user->can('create', InventoryItem::class));
        $this->assertFalse($user->can('viewAny', Supplier::class));
        $this->assertFalse($user->can('create', Supplier::class));
        $this->assertFalse($user->can('viewAny', StockMovement::class));
        $this->assertFalse($user->can('create', StockMovement::class));
    }

    public function test_tenant_owner_retains_inventory_supplier_and_stock_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->assertTrue($owner->can('viewAny', InventoryItem::class));
        $this->assertTrue($owner->can('create', InventoryItem::class));
        $this->assertTrue($owner->can('viewAny', InventoryUnit::class));
        $this->assertTrue($owner->can('create', InventoryUnit::class));
        $this->assertTrue($owner->can('viewAny', Supplier::class));
        $this->assertTrue($owner->can('create', Supplier::class));
        $this->assertTrue($owner->can('viewAny', StockMovement::class));
        $this->assertTrue($owner->can('create', StockMovement::class));
        $this->assertTrue($owner->can('viewAny', StockOpname::class));
        $this->assertTrue($owner->can('create', StockOpname::class));
        $this->assertTrue($owner->can('viewAny', StockTransfer::class));
        $this->assertTrue($owner->can('create', StockTransfer::class));
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug, string $group): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => $group],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }
}
