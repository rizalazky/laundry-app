<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderCustomerPhoneSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_list_displays_copyable_customer_phone_and_can_search_by_it(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $customer = Customer::factory()->for($tenant)->create([
            'name' => 'Customer Phone Search',
            'phone' => '08123456789',
        ]);
        $otherCustomer = Customer::factory()->for($tenant)->create([
            'name' => 'Other Customer',
            'phone' => '08987654321',
        ]);

        $matchingOrder = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
        ]);
        $otherOrder = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $otherCustomer->id,
        ]);

        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'orders.view'],
            ['name' => 'Lihat Order', 'group' => 'Pesanan'],
        );
        RolePermission::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_owner',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$matchingOrder, $otherOrder])
            ->assertSee('628123456789')
            ->searchTable('628123456789')
            ->assertCanSeeTableRecords([$matchingOrder])
            ->assertCanNotSeeTableRecords([$otherOrder]);
    }
}
