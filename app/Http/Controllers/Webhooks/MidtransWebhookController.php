<?php

namespace App\\Http\\Controllers\\Webhooks;

use App\\Http\\Controllers\\Controller;
use App\\Services\\PaymentGateways\\PaymentGatewayInterface;
use App\\Services\\SubscriptionPaymentService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;

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

        $payload = $request->all();

        if (! $gateway->verifyWebhook($payload, $request->headers->all())) {
            return response()->json(['message' => 'Invalid webhook signature.'], 403);
        }

        // Midtrans' dashboard test notification uses a synthetic order ID that
        // does not exist in our payment records. Acknowledge it after signature
        // verification without attempting financial reconciliation.
        if (str_starts_with((string) ($payload['order_id'] ?? ''), 'payment_notif_test_')) {
            return response()->json([
                'received' => true,
                'test' => true,
            ]);
        }

        $result = $payments->handleMidtransWebhook($payload);

        return response()->json([
            'received' => true,
            'result' => $result,
        ]);
    }
}
