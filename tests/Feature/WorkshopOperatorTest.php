<?php

namespace Tests\Feature;

use App\Actions\Orders\ChangeOrderStatus;
use App\Enums\OrderStatus;
use App\Filament\App\Pages\WorkshopOperator;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkshopOperatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_queue_only_contains_orders_from_mapped_outlets(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $outlet = Branch::factory()->for($tenant)->create(['code' => 'OUT-01', 'is_workshop' => false]);
        $workshop = Branch::factory()->for($tenant)->create(['code' => 'WSH-01', 'is_workshop' => true]);
        $unmappedOutlet = Branch::factory()->for($tenant)->create(['code' => 'OUT-02', 'is_workshop' => false]);
        $foreignOutlet = Branch::factory()->for($otherTenant)->create(['code' => 'OUT-03', 'is_workshop' => false]);

        $outlet->syncWorkshops([$workshop->id]);

        $mappedOrder = Order::factory()->for($outlet)->create(['invoice_no' => 'OUT-01-0001']);
        $unmappedOrder = Order::factory()->for($unmappedOutlet)->create(['invoice_no' => 'OUT-02-0001']);
        $foreignOrder = Order::factory()->for($foreignOutlet)->create(['invoice_no' => 'OUT-03-0001']);

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($workshop);

        Livewire::test(WorkshopOperator::class)
            ->assertSee($mappedOrder->invoice_no)
            ->assertDontSee($unmappedOrder->invoice_no)
            ->assertDontSee($foreignOrder->invoice_no);
    }

    public function test_barcode_scan_selects_an_order_and_starts_a_production_stage_with_a_workshop_worker(): void
    {
        $tenant = Tenant::factory()->create();
        $outlet = Branch::factory()->for($tenant)->create(['code' => 'OUT-10', 'is_workshop' => false]);
        $workshop = Branch::factory()->for($tenant)->create(['code' => 'WSH-10', 'is_workshop' => true]);
        $outlet->syncWorkshops([$workshop->id]);

        $order = Order::factory()->for($outlet)->create(['invoice_no' => 'OUT-10-0001']);

        $worker = User::factory()->for($tenant)->withRole('operator')->create(['name' => 'Operator Workshop']);
        $worker->branches()->attach($workshop);

        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($workshop);

        $component = Livewire::test(WorkshopOperator::class)
            ->set('barcode', $order->invoice_no)
            ->call('scanOrder')
            ->assertSet('selectedOrderId', $order->id)
            ->set('workerId', $worker->id)
            ->set('machineCode', 'WASH-01')
            ->call('changeStage', OrderStatus::Washing->value)
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Washing, $order->fresh()->status);

        $productionLog = $order->fresh()->productionLogs()->whereNull('finished_at')->sole();

        $component
            ->assertSee('data-started-at="'.$productionLog->started_at->timestamp.'"', false)
            ->assertSee('window.setInterval', false)
            ->assertSee('1000', false);

        $this->assertDatabaseHas('production_logs', [
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'stage' => 'washer',
            'worker_id' => $worker->id,
            'machine_code' => 'WASH-01',
            'finished_at' => null,
        ]);
    }

    public function test_operator_assigned_to_mapped_workshop_can_update_an_outlet_order(): void
    {
        $tenant = Tenant::factory()->create();
        $outlet = Branch::factory()->for($tenant)->create(['code' => 'OUT-20', 'is_workshop' => false]);
        $workshop = Branch::factory()->for($tenant)->create(['code' => 'WSH-20', 'is_workshop' => true]);
        $outlet->syncWorkshops([$workshop->id]);

        $order = Order::factory()->for($outlet)->create(['invoice_no' => 'OUT-20-0001']);

        $operator = User::factory()->for($tenant)->withRole('operator')->create();
        $operator->branches()->attach($workshop);

        $this->actingAs($operator);

        app(ChangeOrderStatus::class)->handle(
            order: $order,
            to: OrderStatus::Washing,
            user: $operator,
            workerId: $operator->id,
        );

        $this->assertSame(OrderStatus::Washing, $order->fresh()->status);
        $this->assertDatabaseHas('production_logs', [
            'order_id' => $order->id,
            'worker_id' => $operator->id,
            'stage' => 'washer',
        ]);
    }
}
