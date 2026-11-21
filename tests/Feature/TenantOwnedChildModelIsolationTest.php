<?php

namespace Tests\Feature;

use App\Actions\Orders\GenerateInvoiceNumber;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\BranchServicePrice;
use App\Models\Customer;
use App\Models\InvoiceSequence;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantOwnedChildModelIsolationTest extends TestCase
{
    use RefreshDatabase;


    public function test_tenantless_authenticated_users_fail_closed_while_super_admin_can_query_all_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $ownCustomer = Customer::factory()->for($tenant)->create();
        $foreignCustomer = Customer::factory()->for($foreignTenant)->create();
        $branch = Branch::factory()->for($tenant)->create();
        $service = Service::factory()->for($tenant)->create();
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $order->items()->create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'unit' => $service->unit,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
        ]);
        OrderStatusLog::query()->create([
            'order_id' => $order->id,
            'to_status' => OrderStatus::Received,
        ]);
        InvoiceSequence::query()->create([
            'branch_id' => $branch->id,
            'sequence_date' => today(),
            'last_number' => 1,
        ]);
        $tenantlessCashier = User::factory()->withRole('cashier')->create(['tenant_id' => null]);
        $superAdmin = User::factory()->withRole('super_admin')->create(['tenant_id' => null]);

        $this->actingAs($tenantlessCashier);

        $this->assertSame([], Customer::query()->pluck('id')->all());
        $this->assertSame([], OrderItem::query()->pluck('id')->all());
        $this->assertSame([], OrderStatusLog::query()->pluck('id')->all());
        $this->assertSame([], InvoiceSequence::query()->pluck('id')->all());

        try {
            Customer::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Tenantless user attempt',
                'phone' => '081234567890',
            ]);
            $this->fail('An authenticated user without a tenant must not create tenant-owned records.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('customers', [
                'name' => 'Tenantless user attempt',
            ]);
        }

        $this->actingAs($superAdmin);

        $this->assertCount(3, Customer::query()->get());
        $this->assertContains($ownCustomer->id, Customer::query()->pluck('id')->all());
        $this->assertContains($foreignCustomer->id, Customer::query()->pluck('id')->all());
    }

    public function test_tenant_owned_models_scope_queries_and_override_forged_tenant_ids(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $service = Service::factory()->for($tenant)->create();
        $foreignService = Service::factory()->for($foreignTenant)->create();
        $permission = Permission::query()->create(['name' => 'Lihat Pesanan', 'slug' => 'orders.view', 'group' => 'Pesanan']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $ownPrice = BranchServicePrice::query()->create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'price' => 12000,
        ]);
        $foreignPrice = BranchServicePrice::query()->create([
            'branch_id' => $foreignBranch->id,
            'service_id' => $foreignService->id,
            'price' => 15000,
        ]);

        $ownRolePermission = RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);
        $foreignRolePermission = RolePermission::query()->create([
            'tenant_id' => $foreignTenant->id,
            'role' => 'cashier',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($owner);

        $this->assertSame([$ownPrice->id], BranchServicePrice::query()->pluck('id')->all());
        $this->assertSame([$ownRolePermission->id], RolePermission::query()->pluck('id')->all());

        $forgedPrice = $ownPrice->forceFill(['tenant_id' => $foreignTenant->id]);
        $forgedPrice->save();
        $forgedPermission = RolePermission::query()->create([
            'tenant_id' => $foreignTenant->id,
            'role' => 'operator',
            'permission_id' => $permission->id,
        ]);

        $this->assertSame($tenant->id, $forgedPrice->fresh()->tenant_id);
        $this->assertSame($tenant->id, $forgedPermission->fresh()->tenant_id);
        $this->assertNotContains($foreignPrice->id, BranchServicePrice::query()->pluck('id')->all());
        $this->assertNotContains($foreignRolePermission->id, RolePermission::query()->pluck('id')->all());
    }

    public function test_order_items_and_status_logs_are_scoped_through_their_parent_order(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $service = Service::factory()->for($tenant)->create();
        $foreignService = Service::factory()->for($foreignTenant)->create();
        $ownOrder = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $foreignOrder = Order::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'branch_id' => $foreignBranch->id,
        ]);

        $ownItem = $ownOrder->items()->create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'unit' => $service->unit,
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal' => 20000,
        ]);
        $foreignItem = $foreignOrder->items()->create([
            'service_id' => $foreignService->id,
            'service_name' => $foreignService->name,
            'unit' => $foreignService->unit,
            'quantity' => 3,
            'unit_price' => 12000,
            'subtotal' => 36000,
        ]);

        $ownLog = OrderStatusLog::query()->create([
            'order_id' => $ownOrder->id,
            'to_status' => OrderStatus::Received,
        ]);
        $foreignLog = OrderStatusLog::query()->create([
            'order_id' => $foreignOrder->id,
            'to_status' => OrderStatus::Received,
        ]);

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $this->assertSame([$ownItem->id], OrderItem::query()->pluck('id')->all());
        $this->assertSame([$ownLog->id], OrderStatusLog::query()->pluck('id')->all());
        $this->assertNotContains($foreignItem->id, OrderItem::query()->pluck('id')->all());
        $this->assertNotContains($foreignLog->id, OrderStatusLog::query()->pluck('id')->all());
    }

    public function test_tenant_user_cannot_create_order_children_for_a_foreign_order(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $foreignService = Service::factory()->for($foreignTenant)->create();
        $foreignOrder = Order::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'branch_id' => $foreignBranch->id,
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        try {
            OrderItem::query()->create([
                'order_id' => $foreignOrder->id,
                'service_id' => $foreignService->id,
                'service_name' => $foreignService->name,
                'unit' => $foreignService->unit,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
            ]);
            $this->fail('A tenant user must not attach an item to a foreign order.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('order_items', [
                'order_id' => $foreignOrder->id,
                'service_id' => $foreignService->id,
            ]);
        }

        try {
            OrderStatusLog::query()->create([
                'order_id' => $foreignOrder->id,
                'to_status' => OrderStatus::Received,
            ]);
            $this->fail('A tenant user must not append a status log to a foreign order.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('order_status_logs', [
                'order_id' => $foreignOrder->id,
                'to_status' => OrderStatus::Received->value,
            ]);
        }
    }

    public function test_invoice_sequences_are_scoped_and_foreign_branch_numbers_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $ownSequence = InvoiceSequence::query()->create([
            'branch_id' => $branch->id,
            'sequence_date' => today(),
            'last_number' => 4,
        ]);
        $foreignSequence = InvoiceSequence::query()->create([
            'branch_id' => $foreignBranch->id,
            'sequence_date' => today(),
            'last_number' => 9,
        ]);

        $this->actingAs($owner);

        $this->assertSame([$ownSequence->id], InvoiceSequence::query()->pluck('id')->all());
        $this->assertNotContains($foreignSequence->id, InvoiceSequence::query()->pluck('id')->all());

        try {
            InvoiceSequence::query()->create([
                'branch_id' => $foreignBranch->id,
                'sequence_date' => today(),
                'last_number' => 1,
            ]);
            $this->fail('A tenant user must not create an invoice sequence for a foreign branch.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('invoice_sequences', [
                'branch_id' => $foreignBranch->id,
                'last_number' => 1,
            ]);
        }

        try {
            app(GenerateInvoiceNumber::class)->handle($foreignBranch, now());
            $this->fail('A tenant user must not generate an invoice number for a foreign branch.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('invoice_sequences', [
                'branch_id' => $foreignBranch->id,
                'sequence_date' => today()->toDateString(),
            ]);
        }
    }

    public function test_role_permission_service_rejects_mutation_for_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $permission = Permission::query()->create(['name' => 'Lihat Pesanan', 'slug' => 'orders.view', 'group' => 'Pesanan']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        try {
            app(RolePermissionService::class)->sync($foreignTenant, 'cashier', [$permission->id]);
            $this->fail('A tenant owner must not configure another tenant permission matrix.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseMissing('role_permissions', [
                'tenant_id' => $foreignTenant->id,
                'role' => 'cashier',
                'permission_id' => $permission->id,
            ]);
        }
    }
}
