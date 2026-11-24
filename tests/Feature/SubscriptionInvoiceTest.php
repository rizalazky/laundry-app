<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_an_initial_invoice_with_price_and_period_snapshots(): void
    {
        [$tenant, $owner, $plan, $subscription] = $this->makeTenantWithPlan();
        $subscription->forceFill(['trial_ends_at' => now()->addDays(5)])->save();

        $this->actingAs($owner);

        $invoice = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenant, $plan);
        $plan->update(['monthly_price' => '300000.00', 'name' => 'Pro Updated']);

        $this->assertSame('initial', $invoice->type);
        $this->assertSame('Pro', $invoice->plan_name_snapshot);
        $this->assertSame($plan->code, $invoice->plan_code_snapshot);
        $this->assertSame('250000.00', $invoice->unit_price_snapshot);
        $this->assertSame('250000.00', $invoice->total_amount);
        $this->assertSame('IDR', $invoice->currency);
        $this->assertTrue($invoice->period_start->equalTo($subscription->trial_ends_at));
        $this->assertTrue($invoice->period_end->equalTo($subscription->trial_ends_at->copy()->addMonth()));
        $this->assertSame('issued', $invoice->status);
    }

    public function test_owner_can_create_a_renewal_invoice_and_duplicate_open_invoice_is_reused(): void
    {
        [$tenant, $owner, , $subscription] = $this->makeTenantWithPlan();
        $subscription->forceFill([
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(10),
        ])->save();

        $this->actingAs($owner);

        $service = app(SubscriptionInvoiceService::class);
        $invoice = $service->createRenewalInvoice($tenant);
        $duplicate = $service->createRenewalInvoice($tenant);

        $this->assertSame('renewal', $invoice->type);
        $this->assertTrue($invoice->period_start->equalTo($subscription->current_period_end));
        $this->assertTrue($invoice->period_end->equalTo($subscription->current_period_end->copy()->addMonth()));
        $this->assertSame($invoice->id, $duplicate->id);
    }

    public function test_one_invoice_can_have_multiple_payment_attempts_and_attempt_amount_is_snapshotted(): void
    {
        [$tenant, $owner, $plan] = $this->makeTenantWithPlan();
        $this->actingAs($owner);

        $service = app(SubscriptionInvoiceService::class);
        $invoice = $service->createInitialInvoice($tenant, $plan);

        $first = $service->createPaymentAttempt($invoice, 'gateway-a', 'qris', 'attempt-1');
        $first->forceFill(['status' => 'failed'])->save();

        $second = $service->createPaymentAttempt($invoice, 'gateway-a', 'va', 'attempt-2');
        $retry = $service->createPaymentAttempt($invoice, 'gateway-a', 'va', 'attempt-2');

        $this->assertCount(2, $invoice->payments()->get());
        $this->assertSame('250000.00', $first->amount);
        $this->assertSame('250000.00', $second->amount);
        $this->assertSame($second->id, $retry->id);
        $this->assertSame('pending', $second->status);
        $this->assertSame('pending', $invoice->fresh()->status);
    }

    public function test_payment_attempt_creation_does_not_activate_subscription_or_mark_invoice_paid(): void
    {
        [$tenant, $owner, $plan, $subscription] = $this->makeTenantWithPlan();
        $this->actingAs($owner);

        $service = app(SubscriptionInvoiceService::class);
        $invoice = $service->createInitialInvoice($tenant, $plan);
        $service->createPaymentAttempt($invoice, 'gateway-a');

        $this->assertSame('trialing', $subscription->fresh()->status);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
    }

    public function test_read_only_tenant_owner_can_still_create_billing_invoice_and_payment_attempt(): void
    {
        [$tenant, $owner, $plan, $subscription] = $this->makeTenantWithPlan();
        $subscription->forceFill([
            'status' => 'expired',
            'trial_ends_at' => now()->subDay(),
        ])->save();

        $this->actingAs($owner);

        $service = app(SubscriptionInvoiceService::class);
        $invoice = $service->createInitialInvoice($tenant, $plan);
        $attempt = $service->createPaymentAttempt($invoice, 'gateway-a');

        $this->assertSame('issued', $invoice->status);
        $this->assertSame('pending', $attempt->status);
    }

    public function test_staff_and_other_tenant_owners_cannot_create_subscription_invoices(): void
    {
        [$tenant, , $plan] = $this->makeTenantWithPlan();
        $staff = User::factory()->for($tenant)->withRole('cashier')->create();
        $otherTenant = Tenant::factory()->create();
        $otherOwner = User::factory()->for($otherTenant)->withRole('tenant_owner')->create();

        $service = app(SubscriptionInvoiceService::class);

        $this->actingAs($staff);
        try {
            $service->createInitialInvoice($tenant, $plan);
            $this->fail('Staff must not create subscription invoices.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->actingAs($otherOwner);
        $this->expectException(AuthorizationException::class);
        $service->createInitialInvoice($tenant, $plan);
    }

    public function test_zero_value_invoice_cannot_create_payment_attempt(): void
    {
        [$tenant, $owner, $plan] = $this->makeTenantWithPlan('0.00');
        $this->actingAs($owner);

        $invoice = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenant, $plan);

        $this->expectException(ValidationException::class);
        app(SubscriptionInvoiceService::class)->createPaymentAttempt($invoice, 'gateway-a');
    }


    public function test_payment_attempt_cannot_be_created_for_another_tenants_invoice_using_forged_model_attributes(): void
    {
        [$tenantA, $ownerA, $planA] = $this->makeTenantWithPlan();
        $this->actingAs($ownerA);
        $invoiceA = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenantA, $planA);

        // Create the second tenant's subscription without tenant A's auth context.
        // BelongsToTenant correctly overrides tenant_id from the authenticated user.
        auth()->logout();
        [$tenantB, $ownerB] = $this->makeTenantWithPlan();
        $forgedInvoice = new SubscriptionInvoice(['tenant_id' => $tenantB->id]);
        $forgedInvoice->id = $invoiceA->id;

        $this->actingAs($ownerB);
        $this->expectException(AuthorizationException::class);
        app(SubscriptionInvoiceService::class)->createPaymentAttempt($forgedInvoice, 'gateway-a');
    }


    public function test_initial_purchase_invoice_is_rejected_after_a_paid_period_exists(): void
    {
        [$tenant, $owner, $plan, $subscription] = $this->makeTenantWithPlan();
        $subscription->forceFill([
            'status' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ])->save();

        $this->actingAs($owner);
        $this->expectException(ValidationException::class);
        app(SubscriptionInvoiceService::class)->createInitialInvoice($tenant, $plan);
    }

    public function test_renewal_invoice_requires_a_previous_paid_period(): void
    {
        [$tenant, $owner] = $this->makeTenantWithPlan();
        $this->actingAs($owner);

        $this->expectException(ValidationException::class);
        app(SubscriptionInvoiceService::class)->createRenewalInvoice($tenant);
    }

    private function makeTenantWithPlan(string $price = '250000.00'): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $plan = Plan::query()->create([
            'code' => 'pro-'.Str::lower(Str::random(8)),
            'name' => 'Pro',
            'monthly_price' => $price,
            'yearly_price' => '2500000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(30),
        ]);

        return [$tenant, $owner, $plan, $subscription];
    }
}
