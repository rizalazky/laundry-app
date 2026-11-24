<?php

namespace App\Services\PaymentGateways;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MidtransPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(SubscriptionPayment $payment): PaymentGatewayResult
    {
        if ($payment->provider !== 'midtrans') {
            throw new RuntimeException('Payment attempt tidak ditujukan untuk Midtrans.');
        }

        $invoice = $payment->invoice;

        if (! $invoice instanceof SubscriptionInvoice) {
            throw new RuntimeException('Payment attempt harus memiliki invoice subscription.');
        }

        if ($invoice->currency !== 'IDR') {
            throw ValidationException::withMessages([
                'invoice' => 'Midtrans adapter saat ini hanya mendukung invoice IDR.',
            ]);
        }

        $amount = (string) $invoice->total_amount;
        $amountInteger = (int) explode('.', $amount, 2)[0];

        if (! preg_match('/^\d+(?:\.00)?$/D', $amount) || $amountInteger <= 0) {
            throw ValidationException::withMessages([
                'invoice' => 'Nominal invoice Midtrans harus berupa bilangan bulat positif dalam IDR.',
            ]);
        }

        $providerReference = $payment->provider_reference
            ?: sprintf('SUBPAY-%d-%d', $payment->tenant_id, $payment->getKey());

        $expiryHours = (int) config('services.midtrans.expiry_hours', 24);
        $startTime = now();
        $expiresAt = $startTime->copy()->addHours($expiryHours);

        $payload = [
            'transaction_details' => [
                'order_id' => $providerReference,
                'gross_amount' => $amountInteger,
            ],
            'expiry' => [
                'start_time' => $startTime->format('Y-m-d H:i:s O'),
                'unit' => 'hour',
                'duration' => $expiryHours,
            ],
        ];

        if (filled($payment->payment_method)) {
            $payload['enabled_payments'] = [$payment->payment_method];
        }

        $response = $this->request('snap_base_url')
            ->post('/snap/v1/transactions', $payload)
            ->throw();

        $responseData = $response->json();

        if (! is_array($responseData) || blank($responseData['redirect_url'] ?? null)) {
            throw new RuntimeException('Midtrans tidak mengembalikan URL pembayaran yang valid.');
        }

        return new PaymentGatewayResult(
            providerReference: $providerReference,
            paymentUrl: $responseData['redirect_url'],
            expiresAt: $expiresAt,
            providerPayload: $responseData,
        );
    }

    public function getPaymentStatus(string $providerReference): PaymentGatewayStatusResult
    {
        $response = $this->request('api_base_url')
            ->get('/v2/'.rawurlencode($providerReference).'/status')
            ->throw();

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Respons status Midtrans tidak valid.');
        }

        $transactionStatus = strtolower((string) ($data['transaction_status'] ?? 'unknown'));
        $fraudStatus = strtolower((string) ($data['fraud_status'] ?? ''));

        $status = match ($transactionStatus) {
            'settlement' => 'succeeded',
            'capture' => match ($fraudStatus) {
                'accept' => 'succeeded',
                'challenge' => 'pending',
                default => 'failed',
            },
            'pending' => 'pending',
            'expire' => 'expired',
            'cancel', 'deny', 'failure' => 'failed',
            default => 'unknown',
        };

        $paidAt = $data['settlement_time'] ?? null;

        return new PaymentGatewayStatusResult(
            providerReference: $providerReference,
            status: $status,
            paymentMethod: $data['payment_type'] ?? null,
            paidAt: filled($paidAt) ? Carbon::parse($paidAt) : null,
            failureReason: in_array($status, ['failed', 'expired'], true)
                ? ($data['status_message'] ?? $transactionStatus)
                : null,
            providerPayload: $data,
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        $serverKey = (string) config('services.midtrans.server_key');
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signature = strtolower((string) ($payload['signature_key'] ?? ''));

        if ($serverKey === '' || $orderId === '' || $statusCode === '' || $grossAmount === '' || $signature === '') {
            return false;
        }

        $expectedSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        return hash_equals($expectedSignature, $signature);
    }

    public function cancelPayment(string $providerReference): void
    {
        $this->request('api_base_url')
            ->post('/v2/'.rawurlencode($providerReference).'/cancel')
            ->throw();
    }

    private function request(string $baseUrlKey): PendingRequest
    {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
        }

        return Http::baseUrl((string) config('services.midtrans.'.$baseUrlKey))
            ->withBasicAuth($serverKey, '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.midtrans.connect_timeout', 3))
            ->timeout((int) config('services.midtrans.timeout', 10));
    }
}
