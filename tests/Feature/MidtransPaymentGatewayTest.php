<?php

namespace Tests\Feature;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\PaymentGateways\MidtransPaymentGateway;
use App\Services\PaymentGateways\PaymentGatewayInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MidtransPaymentGatewayTest extends TestCase
{
    public function test_it_creates_a_snap_checkout_session_for_a_subscription_payment(): void
    {
        config([
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.midtrans.snap_base_url' => 'https://app.sandbox.midtrans.com',
            'services.midtrans.expiry_hours' => 24,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        $payment = $this->makePayment();
        $result = app(MidtransPaymentGateway::class)->createPayment($payment);

        $this->assertSame('SUBPAY-7-55', $result->providerReference);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token', $result->paymentUrl);
        $this->assertNotNull($result->expiresAt);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            && $request['transaction_details']['order_id'] === 'SUBPAY-7-55'
            && $request['transaction_details']['gross_amount'] === 250000
            && $request['expiry']['duration'] === 24);
    }

    public function test_it_rejects_non_idr_invoice_amounts(): void
    {
        $gateway = app(MidtransPaymentGateway::class);
        $payment = $this->makePayment(currency: 'USD');

        $this->expectException(ValidationException::class);
        $gateway->createPayment($payment);
    }

    public function test_it_rejects_fractional_idr_invoice_amounts(): void
    {
        Http::preventStrayRequests();

        $payment = $this->makePayment(amount: '250000.50');

        $this->expectException(ValidationException::class);
        app(MidtransPaymentGateway::class)->createPayment($payment);
    }

    public function test_it_maps_midtrans_settlement_status_to_succeeded(): void
    {
        config([
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.midtrans.api_base_url' => 'https://api.sandbox.midtrans.com',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/SUBPAY-7-55/status' => Http::response([
                'order_id' => 'SUBPAY-7-55',
                'transaction_status' => 'settlement',
                'payment_type' => 'bank_transfer',
                'settlement_time' => '2026-11-24 10:00:00',
            ]),
        ]);

        $result = app(MidtransPaymentGateway::class)->getPaymentStatus('SUBPAY-7-55');

        $this->assertSame('succeeded', $result->status);
        $this->assertSame('bank_transfer', $result->paymentMethod);
        $this->assertNotNull($result->paidAt);
    }

    public function test_snap_status_not_found_before_payment_method_selection_is_pending(): void
    {
        config([
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.midtrans.api_base_url' => 'https://api.sandbox.midtrans.com',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/SUBPAY-7-55/status' => Http::response([
                'status_code' => '404',
                'status_message' => 'Payment not found',
            ], 404),
        ]);

        $result = app(MidtransPaymentGateway::class)->getPaymentStatus('SUBPAY-7-55');

        $this->assertSame('pending', $result->status);
    }

    public function test_it_verifies_midtrans_webhook_signature(): void
    {
        config(['services.midtrans.server_key' => 'sandbox-server-key']);

        $payload = [
            'order_id' => 'SUBPAY-7-55',
            'status_code' => '200',
            'gross_amount' => '250000.00',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'sandbox-server-key',
        );

        $gateway = app(MidtransPaymentGateway::class);

        $this->assertTrue($gateway->verifyWebhook($payload));
        $this->assertFalse($gateway->verifyWebhook([...$payload, 'gross_amount' => '1.00']));
    }

    public function test_it_cancels_a_midtrans_payment_using_its_order_reference(): void
    {
        config([
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.midtrans.api_base_url' => 'https://api.sandbox.midtrans.com',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/SUBPAY-7-55/cancel' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction is canceled',
            ]),
        ]);

        app(MidtransPaymentGateway::class)->cancelPayment('SUBPAY-7-55');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.sandbox.midtrans.com/v2/SUBPAY-7-55/cancel');
    }

    public function test_payment_gateway_contract_is_bound_to_the_configured_provider(): void
    {
        config(['services.payment_gateway.driver' => 'midtrans']);

        $this->assertInstanceOf(PaymentGatewayInterface::class, app(PaymentGatewayInterface::class));
        $this->assertInstanceOf(MidtransPaymentGateway::class, app(PaymentGatewayInterface::class));
    }

    private function makePayment(string $currency = 'IDR', string $amount = '250000.00'): SubscriptionPayment
    {
        $invoice = new SubscriptionInvoice([
            'total_amount' => $amount,
            'currency' => $currency,
            'plan_name_snapshot' => 'Pro',
        ]);

        $payment = new SubscriptionPayment([
            'tenant_id' => 7,
            'provider' => 'midtrans',
        ]);
        $payment->setAttribute('id', 55);
        $payment->setRelation('invoice', $invoice);

        return $payment;
    }
}
