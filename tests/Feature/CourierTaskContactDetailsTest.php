<?php

namespace Tests\Feature;

use App\Enums\CourierTaskStatus;
use App\Enums\CourierTaskType;
use App\Enums\OrderStatus;
use App\Filament\App\Resources\CourierTasks\Pages\ListCourierTasks;
use App\Models\Branch;
use App\Models\CourierTask;
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

class CourierTaskContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_see_customer_phone_and_copyable_task_address_in_courier_list(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $courier = User::factory()->for($tenant)->withRole('courier')->create();
        $courier->branches()->attach($branch);
        $customer = Customer::factory()->for($tenant)->create([
            'name' => 'Customer Courier Test',
            'phone' => '08123456789',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Received,
        ]);
        $task = CourierTask::query()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'created_by' => $owner->id,
            'task_type' => CourierTaskType::Pickup,
            'address' => 'Jl. Melati No. 5',
            'status' => CourierTaskStatus::Assigned,
        ]);

        $permission = Permission::query()->create([
            'name' => 'Lihat Tugas Kurir',
            'slug' => 'courier_tasks.view',
            'group' => 'Operasional',
        ]);
        RolePermission::query()->create([
            'tenant_id' => $tenant->id,
            'role' => 'tenant_owner',
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        Livewire::test(ListCourierTasks::class)
            ->assertCanSeeTableRecords([$task])
            ->assertSee('628123456789')
            ->assertSee('Jl. Melati No. 5');
    }
}
