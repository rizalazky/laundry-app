<?php

namespace Tests\Feature;

use App\Actions\CourierTasks\CreateCourierTask;
use App\Actions\CourierTasks\UpdateCourierTaskStatus;
use App\Enums\CourierTaskStatus;
use App\Enums\CourierTaskType;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\CourierTask;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_assign_pickup_to_a_courier_assigned_to_the_order_branch(): void
    {
        [$tenant, $branch, $customer, $order, $owner, $courier] = $this->business();

        $task = app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Pickup,
            address: 'Jl. Mawar No. 10',
            courierId: (int) $courier->id,
            user: $owner,
        );

        $this->assertSame($tenant->id, $task->tenant_id);
        $this->assertSame($order->id, $task->order_id);
        $this->assertSame($courier->id, $task->courier_id);
        $this->assertSame(CourierTaskStatus::Assigned, $task->status);
        $this->assertSame('Jl. Mawar No. 10', $task->address);
    }

    public function test_delivery_can_only_be_assigned_after_order_is_ready(): void
    {
        [, , , $order, $owner, $courier] = $this->business();

        try {
            app(CreateCourierTask::class)->handle(
                order: $order,
                type: CourierTaskType::Delivery,
                address: 'Jl. Mawar No. 10',
                courierId: (int) $courier->id,
                user: $owner,
            );
            $this->fail('Delivery should not be assigned before the order is ready.');
        } catch (DomainException $exception) {
            $this->assertSame('Tugas delivery hanya dapat dibuat setelah order siap diambil.', $exception->getMessage());
        }
    }


    public function test_delivery_can_be_assigned_when_order_is_ready(): void
    {
        [, , , $order, $owner, $courier] = $this->business();
        $order->forceFill(['status' => OrderStatus::Ready])->save();

        $task = app(CreateCourierTask::class)->handle(
            order: $order->fresh(),
            type: CourierTaskType::Delivery,
            address: 'Jl. Mawar No. 10',
            courierId: (int) $courier->id,
            user: $owner,
        );

        $this->assertSame(CourierTaskType::Delivery, $task->task_type);
        $this->assertSame(CourierTaskStatus::Assigned, $task->status);
    }

    public function test_pickup_cannot_be_created_twice_while_an_active_task_exists(): void
    {
        [, , , $order, $owner, $courier] = $this->business();

        app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Pickup,
            address: 'Jl. Mawar No. 10',
            courierId: (int) $courier->id,
            user: $owner,
        );

        $this->expectException(DomainException::class);

        app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Pickup,
            address: 'Jl. Mawar No. 10',
            courierId: (int) $courier->id,
            user: $owner,
        );
    }

    public function test_courier_can_start_and_complete_only_their_assigned_task(): void
    {
        [, , , $order, $owner, $courier] = $this->business();
        $task = CourierTask::query()->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'created_by' => $owner->id,
            'task_type' => CourierTaskType::Pickup,
            'address' => 'Jl. Mawar No. 10',
            'status' => CourierTaskStatus::Assigned,
        ]);

        $permission = Permission::query()->create([
            'name' => 'Perbarui Status Tugas Kurir',
            'slug' => 'courier_tasks.update',
            'group' => 'Operasional',
        ]);
        RolePermission::query()->create([
            'tenant_id' => $order->tenant_id,
            'role' => 'courier',
            'permission_id' => $permission->id,
        ]);

        $started = app(UpdateCourierTaskStatus::class)->handle($task, CourierTaskStatus::InProgress, $courier);
        $this->assertSame(CourierTaskStatus::InProgress, $started->status);
        $this->assertNotNull($started->started_at);

        $completed = app(UpdateCourierTaskStatus::class)->handle($started, CourierTaskStatus::Completed, $courier);
        $this->assertSame(CourierTaskStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_operator_cannot_be_assigned_as_a_courier_even_when_in_the_same_branch(): void
    {
        [, , , $order, $owner] = $this->business();
        $operator = User::factory()->for($order->tenant)->withRole('operator')->create();
        $operator->branches()->attach($order->branch_id);

        $this->expectException(DomainException::class);

        app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Pickup,
            address: 'Jl. Mawar No. 10',
            courierId: (int) $operator->id,
            user: $owner,
        );
    }

    public function test_staff_cannot_be_assigned_as_courier_outside_the_order_branch(): void
    {
        [, , , $order, $owner] = $this->business();
        $otherBranch = Branch::factory()->for($order->tenant)->create();
        $courier = User::factory()->for($order->tenant)->withRole('courier')->create();
        $courier->branches()->attach($otherBranch);

        $this->expectException(DomainException::class);

        app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Pickup,
            address: 'Jl. Mawar No. 10',
            courierId: (int) $courier->id,
            user: $owner,
        );
    }

    /**
     * @return array{Tenant, Branch, Customer, Order, User, User}
     */
    private function business(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create(['address' => 'Alamat pelanggan']);
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Received,
        ]);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $courier = User::factory()->for($tenant)->withRole('courier')->create();
        $courier->branches()->attach($branch);

        return [$tenant, $branch, $customer, $order, $owner, $courier];
    }
}
