<?php

namespace Tests\\Feature;

use App\\Models\\Branch;
use App\\Models\\Customer;
use App\\Models\\Order;
use App\\Models\\Tenant;
use App\\Models\\User;
use Illuminate\\Database\\QueryException;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\DB;
use Tests\\TestCase;

class OperationalTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_records_cannot_reference_related_records_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $courier = User::factory()->for($tenant)->withRole('courier')->create();

        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $foreignCustomer = Customer::factory()->for($foreignTenant)->create();
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        $foreignCourier = User::factory()->for($foreignTenant)->withRole('courier')->create();

        $order = Order::factory()->for($branch)->create(['customer_id' => $customer->id]);
        $foreignOrder = Order::factory()->for($foreignBranch)->create(['customer_id' => $foreignCustomer->id]);

        $now = now();

        $attendanceId = DB::table('attendances')->insertGetId([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $owner->id,
            'clock_in' => $now,
            'status' => 'present',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $activityLogId = DB::table('activity_logs')->insertGetId([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => 'test.created',
            'subject_type' => 'test',
            'subject_id' => 1,
            'created_at' => $now,
        ]);

        $courierTaskId = DB::table('courier_tasks')->insertGetId([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'created_by' => $owner->id,
            'task_type' => 'pickup',
            'address' => 'Alamat lokal',
            'status' => 'assigned',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $scheduleId = DB::table('mass_invoice_schedules')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Jadwal lokal',
            'frequency' => 'monthly',
            'run_day' => 1,
            'next_run_at' => $now->copy()->addMonth(),
            'is_active' => true,
            'created_by' => $owner->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pivotId = DB::table('mass_invoice_schedule_customer')->insertGetId([
            'tenant_id' => $tenant->id,
            'mass_invoice_schedule_id' => $scheduleId,
            'customer_id' => $customer->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $massInvoiceId = DB::table('mass_invoices')->insertGetId([
            'tenant_id' => $tenant->id,
            'mass_invoice_schedule_id' => $scheduleId,
            'customer_id' => $customer->id,
            'invoice_no' => 'BULK-TEST-'.$tenant->id,
            'billing_period_start' => $now->toDateString(),
            'billing_period_end' => $now->copy()->addMonth()->toDateString(),
            'total_amount' => 10000,
            'status' => 'issued',
            'trigger_type' => 'manual',
            'generated_at' => $now,
            'triggered_by' => $owner->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $massInvoiceItemId = DB::table('mass_invoice_items')->insertGetId([
            'tenant_id' => $tenant->id,
            'mass_invoice_id' => $massInvoiceId,
            'order_id' => $order->id,
            'balance_snapshot' => 10000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertForeignKeyRejects(
            fn () => DB::table('attendances')->where('id', $attendanceId)->update(['branch_id' => $foreignBranch->id]),
            'Attendance accepted a branch from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('attendances')->where('id', $attendanceId)->update(['user_id' => $foreignOwner->id]),
            'Attendance accepted a user from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('activity_logs')->where('id', $activityLogId)->update(['user_id' => $foreignOwner->id]),
            'Activity log accepted an actor from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('courier_tasks')->where('id', $courierTaskId)->update(['order_id' => $foreignOrder->id]),
            'Courier task accepted an order from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('courier_tasks')->where('id', $courierTaskId)->update(['courier_id' => $foreignCourier->id]),
            'Courier task accepted a courier from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('courier_tasks')->where('id', $courierTaskId)->update(['created_by' => $foreignOwner->id]),
            'Courier task accepted a creator from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoice_schedules')->where('id', $scheduleId)->update(['created_by' => $foreignOwner->id]),
            'Mass invoice schedule accepted a creator from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoice_schedule_customer')->where('id', $pivotId)->update(['customer_id' => $foreignCustomer->id]),
            'Mass invoice schedule accepted a customer from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoice_schedule_customer')->where('id', $pivotId)->update(['mass_invoice_schedule_id' => 999999]),
            'Mass invoice schedule pivot accepted an unrelated schedule.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoices')->where('id', $massInvoiceId)->update(['customer_id' => $foreignCustomer->id]),
            'Mass invoice accepted a customer from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoices')->where('id', $massInvoiceId)->update(['mass_invoice_schedule_id' => 999999]),
            'Mass invoice accepted an unrelated schedule.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoices')->where('id', $massInvoiceId)->update(['triggered_by' => $foreignOwner->id]),
            'Mass invoice accepted an actor from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoice_items')->where('id', $massInvoiceItemId)->update(['mass_invoice_id' => 999999]),
            'Mass invoice item accepted an unrelated invoice.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('mass_invoice_items')->where('id', $massInvoiceItemId)->update(['order_id' => $foreignOrder->id]),
            'Mass invoice item accepted an order from another tenant.',
        );

        $this->assertDatabaseHas('attendances', ['id' => $attendanceId, 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $activityLogId, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('courier_tasks', ['id' => $courierTaskId, 'order_id' => $order->id]);
        $this->assertDatabaseHas('mass_invoice_schedule_customer', ['id' => $pivotId, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('mass_invoices', ['id' => $massInvoiceId, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('mass_invoice_items', ['id' => $massInvoiceItemId, 'order_id' => $order->id]);
    }

    private function assertForeignKeyRejects(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail($message);
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
