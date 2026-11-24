<?php

namespace Tests\\Feature;

use App\\Models\\Plan;
use App\\Models\\Subscription;
use App\\Models\\SubscriptionInvoice;
use App\\Models\\SubscriptionPayment;
use App\\Models\\Tenant;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

class MidtransWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payment_gateway.driver' => 'midtrans',
            'services.midtrans.server_key' => 'webhook-server-key',
            'services.midtrans.api_base_url' => 'https://api.sandbox.midtrans.com',
        ]);

        Http::preventStrayRequests();
    }

    public function test_valid_notification_is_verified_with_midtrans_and_activates_subscription(): void
    {
        [$tenant, $payment, $invoice] = $this->makePendingPayment();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'settlement',
                'payment_type' => 'bank_transfer',
                'gross_amount' => '250000.00',
                'currency' => 'IDR',
                'settlement_time' => '2026-11-24 10:30:00',
            ]),
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $this->signedPayload($payment->provider_reference));

        $response->assertOk()->assertJsonPath('result', 'paid');

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
        $this->assertSame('active', $tenant->subscription()->firstOrFail()->fresh()->status);
        $this->assertTrue($tenant->fresh()->subscription_ends_at->equalTo($invoice->period_end));
        Http::assertSentCount(1);
    }

    public function test_invalid_signature_is_rejected_without_calling_midtrans(): void
    {
        [, $payment] = $this->makePendingPayment();
        $payload = $this->signedPayload($payment->provider_reference);
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_successful_provider_status_with_wrong_amount_does_not_activate_subscription(): void
    {
        [$tenant, $payment, $invoice] = $this->makePendingPayment();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'settlement',
                'gross_amount' => '249999.00',
                'currency' => 'IDR',
            ]),
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $this->signedPayload($payment->provider_reference));

        $response->assertOk()->assertJsonPath('result', 'amount_mismatch');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('trialing', $tenant->subscription()->firstOrFail()->status);
    }

    public function test_duplicate_success_notification_does_not_extend_subscription_twice(): void
    {
        [$tenant, $payment, $invoice] = $this->makePendingPayment();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'settlement',
                'gross_amount' => '250000.00',
                'currency' => 'IDR',
                'settlement_time' => '2026-11-24 10:30:00',
            ]),
        ]);

        $payload = $this->signedPayload($payment->provider_reference);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertOk();
        $periodEndAfterFirstNotification = $tenant->subscription()->firstOrFail()->fresh()->current_period_end;

        $this->postJson(route('webhooks.midtrans'), $payload)
            ->assertOk()
            ->assertJsonPath('result', 'already_processed');

        $this->assertTrue(
            $periodEndAfterFirstNotification->equalTo(
                $tenant->subscription()->firstOrFail()->fresh()->current_period_end,
            ),
        );
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_pending_provider_status_does_not_mark_invoice_paid(): void
    {
        [$tenant, $payment, $invoice] = $this->makePendingPayment();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'pending',
                'gross_amount' => '250000.00',
                'currency' => 'IDR',
            ]),
        ]);

        $this->postJson(route('webhooks.midtrans'), $this->signedPayload($payment->provider_reference))
            ->assertOk()
            ->assertJsonPath('result', 'pending');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('trialing', $tenant->subscription()->firstOrFail()->status);
    }

    private function makePendingPayment(): array
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

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(5),
        ]);

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
            'period_start' => now()->addDays(5),
            'period_end' => now()->addDays(5)->addMonth(),
        ]);

        $payment = SubscriptionPayment::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => $invoice->id,
            'provider' => 'midtrans',
            'provider_reference' => 'SUBPAY-'.$tenant->id.'-'.Str::random(12),
            'amount' => '250000.00',
            'status' => 'pending',
        ]);

        return [$tenant, $payment, $invoice];
    }

    private function signedPayload(string $orderId): array
    {
        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '250000.00',
        ];

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'webhook-server-key',
        );

        return $payload;
    }
}
