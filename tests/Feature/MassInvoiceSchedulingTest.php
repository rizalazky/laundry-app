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
            'customer_id' => $customer->id,
            'total' => '30000.00',
            'paid_amount' => '30000.00',
            'payment_status' => 'paid',
        ]);
        Order::factory()->for($branch)->create([
            'customer_id' => $customer->id,
            'status' => 'cancelled',
            'total' => '15000.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
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

        $newOrder = Order::factory()->for($branch)->create([
            'customer_id' => $customer->id,
            'total' => '8000.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $this->assertSame(1, $generator->handle($schedule, $owner));
        $this->assertSame(2, MassInvoice::query()->count());
        $this->assertSame(1, MassInvoiceItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, MassInvoiceItem::query()->where('order_id', $newOrder->id)->count());
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

        auth()->logout();

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

    public function test_owner_cannot_run_a_foreign_schedule_with_a_forged_tenant_id(): void
    {
        $ownTenant = Tenant::factory()->create();
        $owner = User::factory()->for($ownTenant)->withRole('tenant_owner')->create();

        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $foreignCustomer = Customer::factory()->for($foreignTenant)->create();
        $foreignOrder = Order::factory()->for($foreignBranch)->create([
            'customer_id' => $foreignCustomer->id,
            'total' => '25000.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();

        $this->actingAs($foreignOwner);

        $foreignSchedule = MassInvoiceSchedule::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Jadwal Tenant Lain',
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $foreignSchedule->customers()->attach($foreignCustomer->id, ['tenant_id' => $foreignTenant->id]);

        // Simulate a forged model payload: the record ID remains foreign while
        // its in-memory tenant_id is changed to the authenticated owner's tenant.
        $foreignSchedule->setAttribute('tenant_id', $ownTenant->id);

        $this->actingAs($owner);

        try {
            app(GenerateMassInvoices::class)->handle($foreignSchedule, $owner);
            $this->fail('A tenant owner must not generate invoices from a foreign schedule.');
        } catch (\DomainException $exception) {
            $this->assertSame('Jadwal faktur tidak ditemukan pada tenant ini.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('mass_invoices', [
            'tenant_id' => $foreignTenant->id,
            'mass_invoice_schedule_id' => $foreignSchedule->id,
        ]);
        $this->assertDatabaseMissing('mass_invoice_items', [
            'order_id' => $foreignOrder->id,
        ]);
    }

    public function test_manual_generation_requires_an_authenticated_owner_actor(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $this->actingAs($owner);

        $schedule = MassInvoiceSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jadwal',
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => now()->subDay(),
            'is_active' => true,
        ]);

        auth()->logout();

        $this->expectException(\DomainException::class);
        app(GenerateMassInvoices::class)->handle($schedule);
    }
}
