<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionInvoiceService;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_start_midtrans_checkout_for_an_invoice(): void
    {
        config([
            'services.payment_gateway.driver' => 'midtrans',
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.midtrans.snap_base_url' => 'https://app.sandbox.midtrans.com',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        [$tenant, $owner, $plan] = $this->makeTenantWithPlan();
        $this->actingAs($owner);

        $invoice = app(SubscriptionInvoiceService::class)->createInitialInvoice($tenant, $plan);
        $payment = app(SubscriptionPaymentService::class)->startPayment(
            invoice: $invoice,
            paymentMethod: 'bank_transfer',
            idempotencyKey: 'owner-invoice-payment-1',
        );

        $this->assertSame('midtrans', $payment->provider);
        $this->assertSame('SUBPAY-'.$tenant->id.'-'.$payment->id, $payment->provider_reference);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token', $payment->payment_url);
        $this->assertSame('pending', $payment->status);
        $this->assertDatabaseHas('subscription_payments', [
            'id' => $payment->id,
            'tenant_id' => $tenant->id,
            'provider' => 'midtrans',
            'provider_reference' => $payment->provider_reference,
            'idempotency_key' => 'owner-invoice-payment-1',
        ]);

        $retry = app(SubscriptionPaymentService::class)->startPayment(
            invoice: $invoice,
            paymentMethod: 'bank_transfer',
            idempotencyKey: 'owner-invoice-payment-1',
        );

        $this->assertSame($payment->id, $retry->id);
        Http::assertSentCount(1);
    }

    private function makeTenantWithPlan(): array
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
            'trial_ends_at' => now()->addDays(30),
        ]);

        return [$tenant, $owner, $plan];
    }
}
