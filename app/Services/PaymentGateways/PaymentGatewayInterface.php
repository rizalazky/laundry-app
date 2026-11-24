<?php

namespace App\Services\PaymentGateways;

use App\Models\SubscriptionPayment;

interface PaymentGatewayInterface
{
    public function createPayment(SubscriptionPayment $payment): PaymentGatewayResult;

    public function getPaymentStatus(string $providerReference): PaymentGatewayStatusResult;

    /**
     * Verify the authenticity of a provider webhook without applying business effects.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): bool;

    public function cancelPayment(string $providerReference): void;
}
