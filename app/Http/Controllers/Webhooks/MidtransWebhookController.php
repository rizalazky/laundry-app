<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\PaymentGatewayInterface;
use App\Services\SubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $result = $payments->handleMidtransWebhook($payload);

        return response()->json([
            'received' => true,
            'result' => $result,
        ]);
    }
}
