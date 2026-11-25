<?php

namespace App\\Http\\Controllers\\Webhooks;

use App\\Http\\Controllers\\Controller;
use App\\Services\\PaymentGateways\\PaymentGatewayInterface;
use App\\Services\\SubscriptionPaymentService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Log;

class MidtransWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentGatewayInterface $gateway,
        SubscriptionPaymentService $payments,
    ): JsonResponse {
        if (config('services.payment_gateway.driver') !== 'midtrans') {
            return response()->json(['message' => 'Payment provider is not available.'], 503);
        }

        // Midtrans sends JSON. Read only the JSON object and tolerate additional
        // fields so provider-side payload extensions do not break this endpoint.
        $payload = $request->json()->all();

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
            if (! isset($payload[$field]) || ! is_scalar($payload[$field]) || trim((string) $payload[$field]) === '') {
                return response()->json([
                    'message' => 'Invalid notification payload.',
                    'missing_field' => $field,
                ], 400);
            }
        }

        if (! $gateway->verifyWebhook($payload, $request->headers->all())) {
            Log::warning('Midtrans webhook signature verification failed.', [
                'order_id' => (string) $payload['order_id'],
                'has_server_key' => filled(config('services.midtrans.server_key')),
            ]);

            // Keep the non-2xx response: this is a security/authentication
            // failure, not a notification that may safely be processed.
            return response()->json(['message' => 'Invalid webhook signature.'], 403);
        }

        // Midtrans' dashboard test notification uses a synthetic order ID that
        // does not exist in our payment records. Acknowledge it after signature
        // validation without attempting financial reconciliation.
        if (str_starts_with((string) $payload['order_id'], 'payment_notif_test_')) {
            return response()->json([
                'received' => true,
                'test' => true,
            ]);
        }

        $result = $payments->handleMidtransWebhook($payload);

        // Unknown orders and duplicate/out-of-order notifications are
        // acknowledged by the reconciliation service; transient provider/API
        // failures are allowed to bubble up as 5xx so Midtrans can retry.
        return response()->json([
            'received' => true,
            'result' => $result,
        ]);
    }
}
