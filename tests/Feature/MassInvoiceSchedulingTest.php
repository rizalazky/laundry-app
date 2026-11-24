<?php

namespace Tests\Feature;

use App\Actions\MassInvoices\GenerateMassInvoices;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MassInvoice;
use App\Models\MassInvoiceItem;
use App\Models\MassInvoiceSchedule;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassInvoiceSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_generate_one_invoice_per_selected_customer_from_outstanding_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $unselectedCustomer = Customer::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $order = Order::factory()->for($branch)->create([
            'customer_id' => $customer->id,
            'total' => '50000.00',
            'paid_amount' => '10000.00',
            'payment_status' => 'partial',
        ]);
        Order::factory()->for($branch)->create([
            'customer_id' => $unselectedCustomer->id,
            'total' => '25000.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($owner);

        $schedule = MassInvoiceSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Pelanggan Korporat',
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => now()->addMonth(),
            'is_active' => true,
        ]);
        $schedule->customers()->attach($customer->id, ['tenant_id' => $tenant->id]);

        $generated = app(GenerateMassInvoices::class)->handle($schedule, $owner);

        $this->assertSame(1, $generated);
        $this->assertDatabaseHas('mass_invoices', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'total_amount' => '40000.00',
            'trigger_type' => 'manual',
            'status' => 'issued',
        ]);
        $this->assertDatabaseHas('mass_invoice_items', [
            'mass_invoice_id' => MassInvoice::query()->sole()->id,
            'order_id' => $order->id,
            'balance_snapshot' => '40000.00',
        ]);
        $this->assertSame(0, MassInvoice::query()->where('customer_id', $unselectedCustomer->id)->count());
    }

    public function test_same_order_cannot_be_included_in_more_than_one_mass_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $order = Order::factory()->for($branch)->create([
            'customer_id' => $customer->id,
            'total' => '12000.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($owner);

        $schedule = MassInvoiceSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Bulanan',
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $schedule->customers()->attach($customer->id, ['tenant_id' => $tenant->id]);

        $generator = app(GenerateMassInvoices::class);

        $this->assertSame(1, $generator->handle($schedule, $owner));
        $this->assertSame(0, $generator->handle($schedule, $owner));
        $this->assertSame(1, MassInvoice::query()->count());
        $this->assertSame(1, MassInvoiceItem::query()->where('order_id', $order->id)->count());
    }

    public function test_scheduled_run_advances_next_run_but_manual_run_does_not(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $dueAt = now()->subDay()->startOfWeek()->setTime(1, 0);
        $schedule = MassInvoiceSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jadwal Mingguan',
            'frequency' => 'weekly',
            'run_day' => 1,
            'next_run_at' => $dueAt,
            'is_active' => true,
        ]);
        $schedule->customers()->attach($customer->id, ['tenant_id' => $tenant->id]);

        $generator = app(GenerateMassInvoices::class);
        $nextRunBeforeManual = $schedule->next_run_at->toDateTimeString();

        $generator->handle($schedule, $owner);
        $this->assertSame($nextRunBeforeManual, $schedule->fresh()->next_run_at->toDateTimeString());

        $generator->handle($schedule->fresh(), scheduled: true);
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
    }

    public function test_non_owner_cannot_manually_generate_mass_invoices(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $this->actingAs($cashier);

        $schedule = MassInvoiceSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jadwal',
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => now()->addMonth(),
            'is_active' => true,
        ]);

        $this->expectException(\DomainException::class);
        app(GenerateMassInvoices::class)->handle($schedule, $cashier);
    }
}
