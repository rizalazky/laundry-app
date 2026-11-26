<?php

namespace Tests\\Feature;

use App\\Models\\Plan;
use App\\Models\\Subscription;
use App\\Models\\SubscriptionInvoice;
use App\\Models\\SubscriptionPayment;
use App\\Models\\Tenant;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

class SubscriptionPaymentReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payment_gateway.driver' => 'midtrans',
            'services.midtrans.server_key' => 'reconciliation-server-key',
            'services.midtrans.api_base_url' => 'https://api.sandbox.midtrans.com',
        ]);

        Http::preventStrayRequests();
    }

    public function test_scheduler_reconciles_a_successful_payment_even_when_webhook_was_missed(): void
    {
        [$tenant, $payment, $invoice] = $this->makePendingPayment();

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

        $exitCode = Artisan::call('subscriptions:reconcile-pending-payments');

        $this->assertSame(0, $exitCode);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $tenant->subscription()->firstOrFail()->fresh()->status);
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $tenant->subscription()->value('id'),
            'event_type' => 'payment_received',
            'invoice_id' => $invoice->id,
        ]);
        Http::assertSentCount(1);
    }

    public function test_scheduler_leaves_a_payment_pending_when_provider_still_reports_pending(): void
    {
        [, $payment, $invoice] = $this->makePendingPayment();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->provider_reference.'/status' => Http::response([
                'order_id' => $payment->provider_reference,
                'transaction_status' => 'pending',
                'gross_amount' => '250000.00',
                'currency' => 'IDR',
            ]),
        ]);

        $exitCode = Artisan::call('subscriptions:reconcile-pending-payments');

        $this->assertSame(0, $exitCode);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('trialing', $invoice->subscription->fresh()->status);
        Http::assertSentCount(1);
    }

    private function makePendingPayment(): array
    {
        $tenant = Tenant::factory()->create();
        User::factory()->for($tenant)->withRole('tenant_owner')->create();

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
            'period_start' => now()->subMinute(),
            'period_end' => now()->addMonth(),
        ]);

        $payment = SubscriptionPayment::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_invoice_id' => $invoice->id,
            'provider' => 'midtrans',
            'provider_reference' => 'SUBPAY-'.$tenant->id.'-'.Str::random(12),
            'amount' => '250000.00',
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);

        return [$tenant, $payment, $invoice];
    }
}
