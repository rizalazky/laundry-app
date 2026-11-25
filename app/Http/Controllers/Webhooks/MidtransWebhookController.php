<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use App\Services\PaymentGateways\PaymentGatewayInterface;
use App\Services\PaymentGateways\PaymentWebhookEventRecorder;
use App\Services\SubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MidtransWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentGatewayInterface $gateway,
        SubscriptionPaymentService $payments,
        PaymentWebhookEventRecorder $events,
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
                'has_signature_secret_key' => filled(config('services.midtrans.signature_secret_key')),
            ]);

            // Keep the non-2xx response: this is a security/authentication
            // failure, not a notification that may safely be processed.
            return response()->json(['message' => 'Invalid webhook signature.'], 403);
        }

        // Persist the authenticated event before any reconciliation work. The
        // recorder stores a sanitized payload and a stable event fingerprint.
        $event = $events->recordMidtrans($payload);

        $claim = DB::transaction(function () use ($event): string {
            $lockedEvent = PaymentWebhookEvent::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedEvent->status, ['processed', 'ignored'], true)) {
                return 'already_processed';
            }

            // A duplicate callback must not run concurrently with an active
            // handler. A stale processing claim can be recovered on retry.
            if (
                $lockedEvent->status === 'processing'
                && $lockedEvent->processing_started_at?->greaterThan(now()->subMinutes(5))
            ) {
                return 'already_processing';
            }

            $lockedEvent->forceFill([
                'status' => 'processing',
                'processing_attempts' => $lockedEvent->processing_attempts + 1,
                'processing_started_at' => now(),
                'last_error' => null,
            ])->save();

            return 'process';
        });

        if ($claim === 'already_processed') {
            return response()->json([
                'received' => true,
                'result' => 'already_processed',
            ]);
        }

        if ($claim === 'already_processing') {
            return response()->json([
                'received' => true,
                'result' => 'already_processing',
            ]);
        }

        try {
            // Midtrans' dashboard test notification uses a synthetic order ID
            // that does not exist in our payment records. Persist and acknowledge
            // it after signature validation without financial reconciliation.
            if (str_starts_with((string) $payload['order_id'], 'payment_notif_test_')) {
                $result = 'test_notification';
                $eventStatus = 'ignored';
            } else {
                $result = $payments->handleMidtransWebhook($payload);
                $eventStatus = $result === 'unknown_payment' ? 'ignored' : 'processed';
            }

            $event->forceFill([
                'status' => $eventStatus,
                'processing_result' => $result,
                'processing_started_at' => null,
                'processed_at' => now(),
                'last_error' => null,
            ])->save();

            if ($result === 'test_notification') {
                return response()->json([
                    'received' => true,
                    'test' => true,
                ]);
            }

            // Unknown orders and duplicate/out-of-order notifications are
            // acknowledged by the reconciliation service; transient provider/API
            // failures are allowed to bubble up as 5xx so Midtrans can retry.
            return response()->json([
                'received' => true,
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'processing_started_at' => null,
                'last_error' => class_basename($exception),
            ])->save();

            throw $exception;
        }
    }
}
