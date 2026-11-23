<?php

namespace Tests\Feature;

use App\Actions\CourierTasks\CreateCourierTask;
use App\Actions\CourierTasks\UpdateCourierTaskStatus;
use App\Actions\Orders\ChangeOrderStatus;
use App\Enums\CourierTaskStatus;
use App\Enums\CourierTaskType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\App\Pages\PointOfSale;
use App\Filament\App\Pages\WorkshopOperator;
use App\Models\Branch;
use App\Models\CourierTask;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionLog;
use App\Models\RolePermission;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_moves_from_pos_through_pickup_production_delivery_and_completion(): void
    {
        $tenant = Tenant::factory()->create(['max_branches' => 3]);
        $outlet = Branch::factory()->for($tenant)->create([
            'code' => 'OUT-INT',
            'is_workshop' => false,
            'name' => 'Outlet Integrasi',
        ]);
        $workshop = Branch::factory()->for($tenant)->create([
            'code' => 'WSH-INT',
            'is_workshop' => true,
            'name' => 'Workshop Integrasi',
        ]);
        $outlet->syncWorkshops([$workshop->id]);

        $customer = Customer::factory()->for($tenant)->create([
            'name' => 'Pelanggan Integrasi',
            'address' => 'Jl. Integrasi No. 10',
        ]);
        $service = Service::factory()->for($tenant)->create([
            'name' => 'Cuci Kiloan',
            'price' => 10000,
            'estimated_hours' => 24,
        ]);

        $cashier = $this->staff($tenant, $outlet, 'cashier', ['orders.create', 'orders.view']);
        $operator = $this->staff($tenant, $workshop, 'operator', ['orders.view']);
        $courier = $this->staff($tenant, $outlet, 'courier', ['courier_tasks.update']);
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        // POS: create an order and settle it through the cashier payment flow.
        $this->actingAs($cashier);
        Filament::setCurrentPanel('app');
        Filament::setTenant($outlet);

        $pos = Livewire::test(PointOfSale::class)
            ->call('selectCustomer', (int) $customer->getKey())
            ->call('addToCart', (int) $service->getKey())
            ->set('cart.'.(int) $service->getKey(), '3')
            ->call('openPaymentModal')
            ->assertSet('paymentLines.0.amount', '30000.00')
            ->set('paymentLines.0.method', PaymentMethod::Cash->value)
            ->call('createOrder')
            ->assertHasNoErrors()
            ->assertNotified('Order berhasil dibuat');

        $order = Order::query()->sole();

        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame('30000.00', $order->total);
        $this->assertSame('30000.00', $order->paid_amount);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame($workshop->id, $order->workshop_id);
        $pos->assertSee($order->invoice_no);

        // Dispatch tasks as the owner, then process them as the assigned courier.
        $this->actingAs($owner);
        $pickup = app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Pickup,
            address: $customer->address,
            courierId: (int) $courier->getKey(),
            user: $owner,
        );

        $this->actingAs($courier);
        app(UpdateCourierTaskStatus::class)->handle($pickup, CourierTaskStatus::InProgress, $courier);
        $pickup = app(UpdateCourierTaskStatus::class)->handle(
            $pickup->fresh(),
            CourierTaskStatus::Completed,
            $courier,
        );

        $this->assertSame(CourierTaskStatus::Completed, $pickup->status);

        // Workshop: an operator scans/selects the order and completes each production stage.
        $this->actingAs($operator);
        Filament::setCurrentPanel('app');
        Filament::setTenant($workshop);

        $workshopPage = Livewire::test(WorkshopOperator::class)
            ->call('selectOrder', (int) $order->getKey());

        foreach ([
            OrderStatus::Washing,
            OrderStatus::Drying,
            OrderStatus::Ironing,
            OrderStatus::Packing,
            OrderStatus::Ready,
        ] as $stage) {
            $workshopPage
                ->call('changeStage', $stage->value)
                ->assertHasNoErrors()
                ->assertNotified('Tahap produksi diperbarui');
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Ready, $order->status);
        $this->assertSame(4, ProductionLog::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            0,
            ProductionLog::query()->where('order_id', $order->id)->whereNull('finished_at')->count(),
        );

        // Delivery: dispatch only after production reaches Ready.
        $this->actingAs($owner);
        $delivery = app(CreateCourierTask::class)->handle(
            order: $order,
            type: CourierTaskType::Delivery,
            address: $customer->address,
            courierId: (int) $courier->getKey(),
            user: $owner,
        );

        $this->actingAs($courier);
        app(UpdateCourierTaskStatus::class)->handle($delivery, CourierTaskStatus::InProgress, $courier);
        $delivery = app(UpdateCourierTaskStatus::class)->handle(
            $delivery->fresh(),
            CourierTaskStatus::Completed,
            $courier,
        );

        $this->assertSame(CourierTaskStatus::Completed, $delivery->status);

        // Final handover: a cashier can complete a ready, fully paid order.
        $this->actingAs($cashier);

        app(ChangeOrderStatus::class)->handle(
            order: $order->fresh(),
            to: OrderStatus::Completed,
            user: $cashier,
        );

        $order->refresh();

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(2, CourierTask::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, CourierTask::query()
            ->where('order_id', $order->id)
            ->where('status', CourierTaskStatus::Completed)
            ->count());
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Ready->value,
            'to_status' => OrderStatus::Completed->value,
        ]);

        $this->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee($order->invoice_no)
            ->assertSee('Pelanggan Integrasi')
            ->assertSee('LUNAS');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staff(Tenant $tenant, Branch $branch, string $role, array $permissions): User
    {
        $user = User::factory()->for($tenant)->withRole($role)->create();
        $user->branches()->attach($branch);

        foreach ($permissions as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Operasional'],
            );

            RolePermission::query()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'role' => $role,
                'permission_id' => $permission->id,
            ]);
        }

        return $user;
    }
}
