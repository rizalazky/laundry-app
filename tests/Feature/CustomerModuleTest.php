<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CustomerModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_customers_are_visible_from_each_branch_but_never_from_another_tenant(): void
    {
        $tenant = $this->createTenant('laundry-one');
        $otherTenant = $this->createTenant('laundry-two');
        $central = $this->createBranch($tenant, 'PUSAT');
        $branch = $this->createBranch($tenant, 'DGO');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $tenantCustomer = $this->createCustomer($tenant, 'Pelanggan Satu', '0812-3456-789');
        $otherCustomer = $this->createCustomer($otherTenant, 'Pelanggan Rahasia', '0813-1111-222');

        $this->actingAs($owner);

        foreach ([$central, $branch] as $activeBranch) {
            $response = $this->get('/app/' . $activeBranch->code . '/customers');

            $response->assertOk()
                ->assertSee('Pelanggan Satu')
                ->assertDontSee('Pelanggan Rahasia');
        }

        $this->assertSame((string) $tenantCustomer->id, (string) Customer::query()->where('name', 'Pelanggan Satu')->value('id'));
        $this->assertDatabaseHas('customers', ['id' => $otherCustomer->id, 'tenant_id' => $otherTenant->id]);
    }

    public function test_customer_record_from_another_tenant_returns_404_on_edit_url(): void
    {
        $tenant = $this->createTenant('laundry-id-owner');
        $otherTenant = $this->createTenant('laundry-id-other');
        $branch = $this->createBranch($tenant, 'PUSAT');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $foreignCustomer = $this->createCustomer($otherTenant, 'Customer Tenant B');

        $this->actingAs($owner)
            ->get('/app/' . $branch->code . '/customers/' . $foreignCustomer->id . '/edit')
            ->assertNotFound();
    }

    public function test_cashiers_and_operators_can_view_customers_but_cannot_create_or_edit_them(): void
    {
        Filament::setCurrentPanel('app');

        $tenant = $this->createTenant('laundry-read-only');
        $branch = $this->createBranch($tenant, 'PUSAT');
        $customer = $this->createCustomer($tenant, 'Customer Read Only');

        foreach (['cashier', 'operator'] as $role) {
            $user = $this->createUser($tenant, $role);
            $user->branches()->attach($branch);
            $this->grantPermission($tenant, $role, 'customers.view');

            $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
            $this->assertTrue($user->canAccessTenant($branch));

            Filament::auth()->login($user);
            $response = $this->get('/app/' . $branch->code . '/customers');

            $this->assertFalse(
                $response->isRedirect(),
                'Customer list redirected to ' . $response->headers->get('Location')
            );

            $response->assertOk()->assertSee('Customer Read Only');

            Filament::auth()->login($user);
            $this->get('/app/' . $branch->code . '/customers/create')
                ->assertForbidden();

            Filament::auth()->login($user);
            $this->get('/app/' . $branch->code . '/customers/' . $customer->id . '/edit')
                ->assertForbidden();
        }
    }

    public function test_customer_view_permission_is_required_even_for_active_tenant_staff(): void
    {
        $tenant = $this->createTenant('laundry-customer-view-denied');
        $branch = $this->createBranch($tenant, 'PUSAT');
        $cashier = $this->createUser($tenant, 'cashier');
        $cashier->branches()->attach($branch);

        $this->actingAs($cashier)
            ->get('/app/' . $branch->code . '/customers')
            ->assertForbidden();
    }

    public function test_customer_manage_permission_is_required_for_branch_managers(): void
    {
        $tenant = $this->createTenant('laundry-customer-manage-denied');
        $branch = $this->createBranch($tenant, 'PUSAT');
        $manager = $this->createUser($tenant, 'branch_manager');
        $manager->branches()->attach($branch);
        $this->grantPermission($tenant, 'branch_manager', 'customers.view');

        $this->actingAs($manager)
            ->get('/app/' . $branch->code . '/customers')
            ->assertOk();

        $this->actingAs($manager)
            ->get('/app/' . $branch->code . '/customers/create')
            ->assertForbidden();

        $this->grantPermission($tenant, 'branch_manager', 'customers.manage');

        $this->actingAs($manager)
            ->get('/app/' . $branch->code . '/customers/create')
            ->assertOk();
    }

    private function grantPermission(Tenant $tenant, string $role, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'group' => 'Pelanggan'],
        );

        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => $role,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_customer_validation_normalizes_contacts_and_soft_deleted_contacts_can_be_reused(): void
    {
        $tenant = $this->createTenant('laundry-contacts');
        $customer = $this->createCustomer($tenant, 'Existing Customer', '0812-3456-789');
        $this->assertSame('628123456789', $customer->phone);
        $this->assertSame('628123456789', Customer::normalizePhone('+62 812 3456 789'));

        $this->assertFalse(Validator::make(['name' => ''], ['name' => ['required']])->passes());

        $customer->delete();
        $this->assertNotNull(Customer::withTrashed()->findOrFail($customer->id)->deleted_at);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id, 'deleted_at' => null]);

        $replacement = $this->createCustomer($tenant, 'Replacement Customer', '628123456789');
        $this->assertNotSame($customer->id, $replacement->id);
        $this->assertDatabaseHas('customers', ['id' => $replacement->id, 'deleted_at' => null]);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => $slug,
            'slug' => $slug,
            'owner_email' => $slug . '@example.test',
            'plan' => 'starter',
            'max_branches' => 3,
            'is_active' => true,
        ]);
    }

    private function createBranch(Tenant $tenant, string $code): Branch
    {
        return $tenant->branches()->create([
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function createUser(Tenant $tenant, string $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $role,
            'email' => $role . '-' . $tenant->slug . '@example.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createCustomer(Tenant $tenant, string $name, ?string $phone = null): Customer
    {
        return $tenant->customers()->create([
            'name' => $name,
            'phone' => $phone,
            'email' => null,
        ]);
    }

    public function test_customer_order_history_shows_orders_from_all_tenant_branches_to_owner(): void
    {
        $tenant = $this->createTenant('laundry-history-owner');
        $central = $this->createBranch($tenant, 'PUSAT');
        $branch = $this->createBranch($tenant, 'DGO');
        $owner = $this->createUser($tenant, 'tenant_owner');
        $customer = $this->createCustomer($tenant, 'Customer History');

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $central->id,
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-CENTRAL-001',
        ]);
        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-BRANCH-001',
        ]);

        $this->actingAs($owner)
            ->get('/app/' . $central->code . '/customers/' . $customer->id . '/edit')
            ->assertOk()
            ->assertSee('INV-CENTRAL-001')
            ->assertSee('INV-BRANCH-001');
    }

    public function test_customer_order_history_only_shows_orders_from_branches_assigned_to_staff(): void
    {
        $tenant = $this->createTenant('laundry-history-staff');
        $assignedBranch = $this->createBranch($tenant, 'PUSAT');
        $otherBranch = $this->createBranch($tenant, 'DGO');
        $manager = $this->createUser($tenant, 'branch_manager');
        $manager->branches()->attach($assignedBranch);

        $this->grantPermission($tenant, 'branch_manager', 'customers.view');
        $this->grantPermission($tenant, 'branch_manager', 'orders.view');

        $customer = $this->createCustomer($tenant, 'Customer Branch History');

        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $assignedBranch->id,
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-ASSIGNED-001',
        ]);
        Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-UNASSIGNED-001',
        ]);

        $this->actingAs($manager)
            ->get('/app/' . $assignedBranch->code . '/customers/' . $customer->id . '/edit')
            ->assertOk()
            ->assertSee('INV-ASSIGNED-001')
            ->assertDontSee('INV-UNASSIGNED-001');
    }

}
