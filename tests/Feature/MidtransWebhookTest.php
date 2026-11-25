<?php

namespace Tests\Feature;

use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Services\PaymentGateways\PaymentWebhookEventRecorder;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MidtransWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payment_gateway.driver' => 'midtrans',
            'services.midtrans.server_key' => 'webhook-server-key',
            'services.midtrans.signature_secret_key' => 'webhook-signature-secret',
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
        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame('midtrans', $event->provider);
        $this->assertSame('processed', $event->status);
        $this->assertSame('paid', $event->processing_result);
        $this->assertSame(1, $event->processing_attempts);
        $this->assertNotNull($event->signature_verified_at);
        $this->assertArrayNotHasKey('signature_key', $event->payload);
        Http::assertSentCount(1);
    }

    public function test_invalid_signature_is_rejected_without_calling_midtrans(): void
    {
        [, $payment] = $this->makePendingPayment();
        $payload = $this->signedPayload($payment->provider_reference);
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, PaymentWebhookEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_server_key_signature_is_rejected_when_signature_secret_key_is_configured(): void
    {
        [, $payment] = $this->makePendingPayment();
        $payload = [
            'order_id' => $payment->provider_reference,
            'status_code' => '200',
            'gross_amount' => '250000.00',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'webhook-server-key',
        );

        $this->postJson(route('webhooks.midtrans'), $payload)->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_notification_with_missing_required_fields_returns_bad_request(): void
    {
        $this->postJson(route('webhooks.midtrans'), [
            'order_id' => 'SUBPAY-missing-fields',
        ])->assertBadRequest();

        Http::assertNothingSent();
    }

    public function test_late_pending_notification_does_not_downgrade_failed_payment(): void
    {
        [, $payment] = $this->makePendingPayment();

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => 'Payment was denied.',
        ])->save();

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
            ->assertJsonPath('result', 'stale_notification_ignored');

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('Payment was denied.', $payment->fresh()->failure_reason);
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
        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertSame(1, PaymentWebhookEvent::query()->sole()->processing_attempts);
        Http::assertSentCount(1);
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


    public function test_signed_midtrans_dashboard_test_notification_is_acknowledged_without_reconciliation(): void
    {
        $payload = [
            'order_id' => 'payment_notif_test_M171656689_test-id',
            'status_code' => '200',
            'gross_amount' => '105000.00',
        ];

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'webhook-signature-secret',
        );

        $this->postJson(route('webhooks.midtrans'), $payload)
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('test', true);

        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame('ignored', $event->status);
        $this->assertSame('test_notification', $event->processing_result);
        Http::assertNothingSent();
    }


    public function test_failed_provider_reconciliation_is_persisted_and_can_be_retried(): void
    {
        [, $payment] = $this->makePendingPayment();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::sequence()
                ->push(['message' => 'Temporary provider outage.'], 503)
                ->push([
                    'order_id' => $payment->provider_reference,
                    'transaction_status' => 'settlement',
                    'payment_type' => 'bank_transfer',
                    'gross_amount' => '250000.00',
                    'currency' => 'IDR',
                    'settlement_time' => '2026-11-24 10:30:00',
                ]),
        ]);

        $payload = $this->signedPayload($payment->provider_reference);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertServerError();

        $event = PaymentWebhookEvent::query()->sole();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->processing_attempts);
        $this->assertNotNull($event->last_error);

        $this->postJson(route('webhooks.midtrans'), $payload)
            ->assertOk()
            ->assertJsonPath('result', 'paid');

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertSame(2, $event->processing_attempts);
        $this->assertNotNull($event->processed_at);
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_distinct_midtrans_transaction_statuses_have_distinct_event_fingerprints(): void
    {
        $recorder = app(PaymentWebhookEventRecorder::class);

        $base = [
            'order_id' => 'SUBPAY-STATUS-TRANSITIONS',
            'transaction_id' => 'midtrans-transaction-123',
            'status_code' => '200',
            'gross_amount' => '250000.00',
            'transaction_time' => '2026-11-25 10:00:00',
        ];

        $pending = $recorder->recordMidtrans([...$base, 'transaction_status' => 'pending']);
        $settled = $recorder->recordMidtrans([...$base, 'transaction_status' => 'settlement']);

        $this->assertNotSame($pending->provider_event_id, $settled->provider_event_id);
        $this->assertSame(2, PaymentWebhookEvent::query()->count());
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
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'webhook-signature-secret',
        );

        return $payload;
    }
}
