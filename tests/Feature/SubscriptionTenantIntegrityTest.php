<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_records_cannot_reference_financial_records_or_actors_from_another_tenant(): void
    {
        $plan = Plan::query()->create([
            'code' => 'tenant-integrity-'.uniqid(),
            'name' => 'Tenant Integrity',
            'monthly_price' => 99000,
        ]);

        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignTenant = Tenant::factory()->create();
        $foreignOwner = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();
        $foreignSubscriptionId = DB::table('subscriptions')->insertGetId([
            'tenant_id' => $foreignTenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        $invoiceId = $this->createInvoice($tenant->id, $subscriptionId, 'LOCAL');
        $foreignInvoiceId = $this->createInvoice($foreignTenant->id, $foreignSubscriptionId, 'FOREIGN');

        $paymentId = $this->createPayment($tenant->id, $invoiceId, 'LOCAL');
        $foreignPaymentId = $this->createPayment($foreignTenant->id, $foreignInvoiceId, 'FOREIGN');

        $eventId = DB::table('subscription_events')->insertGetId([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'actor_id' => $owner->id,
            'event_type' => 'payment_received',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reminderId = DB::table('subscription_renewal_reminders')->insertGetId([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscriptionId,
            'period_end' => $now->copy()->addMonth(),
            'days_before_expiry' => 7,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_invoices')->where('id', $invoiceId)->update([
                'subscription_id' => $foreignSubscriptionId,
            ]),
            'Subscription invoice accepted a subscription from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_payments')->where('id', $paymentId)->update([
                'subscription_invoice_id' => $foreignInvoiceId,
            ]),
            'Subscription payment accepted an invoice from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_events')->where('id', $eventId)->update([
                'subscription_id' => $foreignSubscriptionId,
            ]),
            'Subscription event accepted a subscription from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_events')->where('id', $eventId)->update([
                'invoice_id' => $foreignInvoiceId,
            ]),
            'Subscription event accepted an invoice from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_events')->where('id', $eventId)->update([
                'payment_id' => $foreignPaymentId,
            ]),
            'Subscription event accepted a payment from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_events')->where('id', $eventId)->update([
                'actor_id' => $foreignOwner->id,
            ]),
            'Subscription event accepted an actor from another tenant.',
        );
        $this->assertForeignKeyRejects(
            fn () => DB::table('subscription_renewal_reminders')->where('id', $reminderId)->update([
                'subscription_id' => $foreignSubscriptionId,
            ]),
            'Renewal reminder accepted a subscription from another tenant.',
        );

        $this->assertDatabaseHas('subscription_invoices', [
            'id' => $invoiceId,
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscriptionId,
        ]);
        $this->assertDatabaseHas('subscription_payments', [
            'id' => $paymentId,
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => $invoiceId,
        ]);
        $this->assertDatabaseHas('subscription_events', [
            'id' => $eventId,
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscriptionId,
            'actor_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('subscription_renewal_reminders', [
            'id' => $reminderId,
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscriptionId,
        ]);
    }

    private function createInvoice(int $tenantId, int $subscriptionId, string $suffix): int
    {
        $now = now();

        return DB::table('subscription_invoices')->insertGetId([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'invoice_number' => 'SUB-'.$suffix.'-'.uniqid(),
            'type' => 'renewal',
            'plan_code_snapshot' => 'tenant-integrity',
            'plan_name_snapshot' => 'Tenant Integrity',
            'billing_interval' => 'monthly',
            'unit_price_snapshot' => 99000,
            'subtotal' => 99000,
            'discount_amount' => 0,
            'total_amount' => 99000,
            'currency' => 'IDR',
            'status' => 'issued',
            'period_start' => $now,
            'period_end' => $now->copy()->addMonth(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createPayment(int $tenantId, int $invoiceId, string $suffix): int
    {
        $now = now();

        return DB::table('subscription_payments')->insertGetId([
            'tenant_id' => $tenantId,
            'subscription_invoice_id' => $invoiceId,
            'provider' => 'test-'.$suffix,
            'amount' => 99000,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
