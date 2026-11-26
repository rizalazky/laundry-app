<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionInvoiceService;
use App\Services\SubscriptionPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionInvoiceExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payment_gateway.driver' => 'midtrans',
            'services.midtrans.server_key' => 'invoice-expiry-server-key',
            'services.midtrans.api_base_url' => 'https://api.sandbox.midtrans.com',
            'services.midtrans.snap_base_url' => 'https://app.sandbox.midtrans.com',
            'services.subscription.invoice_validity_hours' => 24,
        ]);

        Http::preventStrayRequests();
    }

    public function test_new_subscription_invoice_is_valid_for_exactly_24_hours(): void
    {
        [$tenant, $owner, $plan] = $this->makeTenantPlanAndSubscription();
        Auth::login($owner);

        $invoice = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenant, $plan);

        $this->assertTrue($invoice->due_at->equalTo($invoice->created_at->copy()->addHours(24)));
    }

    public function test_expiry_command_marks_due_unpaid_invoice_expired_and_records_event(): void
    {
        [$tenant, $invoice] = $this->makeInvoiceAndPayment(now()->subSecond());

        $this->assertSame(0, Artisan::call('subscriptions:expire-invoices'));

        $this->assertSame('expired', $invoice->fresh()->status);
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $tenant->subscription()->value('id'),
            'event_type' => 'invoice_expired',
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_owner_cannot_start_a_payment_for_an_expired_invoice(): void
    {
        [$tenant, $owner] = $this->makeTenantPlanAndSubscription();
        Auth::login($owner);
        [, $invoice] = $this->makeInvoiceAndPayment(now()->subSecond(), $tenant);

        try {
            app(SubscriptionInvoiceService::class)->createPaymentAttempt($invoice, 'midtrans');
            $this->fail('Expected expired invoice payment attempt to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
    }

    public function test_late_successful_payment_is_recorded_but_does_not_activate_expired_invoice(): void
    {
        [$tenant, $invoice, $payment] = $this->makeInvoiceAndPayment(now()->subMinute());
        $invoice->forceFill(['status' => 'expired'])->save();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'settlement',
                'payment_type' => 'bank_transfer',
                'gross_amount' => '250000.00',
                'currency' => 'IDR',
                'settlement_time' => now()->toDateTimeString(),
            ]),
        ]);

        $result = app(SubscriptionPaymentReconciliationService::class)->handle([
            'order_id' => $payment->provider_reference,
        ]);

        $this->assertSame('late_payment_requires_review', $result);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('expired', $invoice->fresh()->status);
        $this->assertSame('trialing', $tenant->subscription()->firstOrFail()->status);
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $tenant->subscription()->value('id'),
            'event_type' => 'payment_received',
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_payment_settled_before_deadline_can_be_accepted_after_expiry_command_ran(): void
    {
        $paidAt = now()->subMinutes(6);
        [$tenant, $invoice, $payment] = $this->makeInvoiceAndPayment(now()->subMinutes(5));

        $invoice->forceFill(['status' => 'expired'])->save();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'settlement',
                'payment_type' => 'bank_transfer',
                'gross_amount' => '250000.00',
                'currency' => 'IDR',
                'settlement_time' => $paidAt->toDateTimeString(),
            ]),
        ]);

        $result = app(SubscriptionPaymentReconciliationService::class)->handle([
            'order_id' => $payment->provider_reference,
        ]);

        $this->assertSame('paid', $result);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $tenant->subscription()->firstOrFail()->status);
    }

    private function makeTenantPlanAndSubscription(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $plan = Plan::query()->create([
            'code' => 'pro-'.Str::lower(Str::random(8)),
            'name' => 'Pro',
            'monthly_price' => '250000.00',
            'yearly_price' => '2500000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(5),
        ]);

        return [$tenant, $owner, $plan];
    }

    private function makeInvoiceAndPayment($dueAt, ?Tenant $tenant = null): array
    {
        if ($tenant === null) {
            [$tenant, , $plan] = $this->makeTenantPlanAndSubscription();
        } else {
            $plan = Plan::query()->where('is_active', true)->firstOrFail();
        }

        $subscription = $tenant->subscription()->firstOrFail();

        $invoice = SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'invoice_number' => 'SUB-'.$tenant->id.'-'.Str::upper(Str::random(8)),
            'type' => 'initial',
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
            'billing_interval' => 'monthly',
            'unit_price_snapshot' => '250000.00',
            'subtotal' => '250000.00',
            'discount_amount' => '0.00',
            'total_amount' => '250000.00',
            'currency' => 'IDR',
            'status' => 'pending',
            'period_start' => now()->subMinute(),
            'period_end' => now()->addMonth(),
            'due_at' => $dueAt,
        ]);

        $payment = SubscriptionPayment::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => $invoice->id,
            'provider' => 'midtrans',
            'provider_reference' => 'SUBPAY-'.Str::random(16),
            'amount' => '250000.00',
            'status' => 'pending',
            'expires_at' => $dueAt,
        ]);

        return [$tenant, $invoice, $payment];
    }
}
