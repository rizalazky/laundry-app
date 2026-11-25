<?php

namespace App\Services\PaymentGateways;

use App\Models\PaymentWebhookEvent;

class PaymentWebhookEventRecorder
{
    /**
     * Persist a verified Midtrans notification without retaining its signature
     * or payment-instrument-specific details.
     *
     * Midtrans notifications do not expose a separate stable event ID. Build a
     * deterministic fingerprint from the provider transaction and status
     * fields so retries deduplicate while later status transitions remain
     * distinct events.
     *
     * @param array<string, mixed> $payload
     */
    public function recordMidtrans(array $payload): PaymentWebhookEvent
    {
        $identity = [];

        foreach ([
            'order_id',
            'transaction_id',
            'transaction_status',
            'status_code',
            'gross_amount',
            'currency',
            'payment_type',
            'fraud_status',
            'transaction_time',
            'settlement_time',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $identity[$field] = is_scalar($payload[$field])
                    ? (string) $payload[$field]
                    : $payload[$field];
            }
        }

        ksort($identity);

        $providerEventId = hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $safePayload = [];

        foreach ([
            'order_id',
            'transaction_id',
            'transaction_status',
            'status_code',
            'gross_amount',
            'currency',
            'payment_type',
            'fraud_status',
            'transaction_time',
            'settlement_time',
            'merchant_id',
            'status_message',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $safePayload[$field] = $payload[$field];
            }
        }

        return PaymentWebhookEvent::query()->firstOrCreate(
            [
                'provider' => 'midtrans',
                'provider_event_id' => $providerEventId,
            ],
            [
                'provider_reference' => (string) ($payload['order_id'] ?? ''),
                'event_type' => (string) ($payload['transaction_status'] ?? 'notification'),
                'payload' => $safePayload,
                'status' => 'received',
                'signature_verified_at' => now(),
            ],
        );
    }
}
