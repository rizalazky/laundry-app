<?php

namespace App\Services\PaymentGateways;

use Illuminate\Support\Carbon;

final readonly class PaymentGatewayStatusResult
{
    /**
     * @param array<string, mixed> $providerPayload
     */
    public function __construct(
        public string $providerReference,
        public string $status,
        public ?string $paymentMethod = null,
        public ?Carbon $paidAt = null,
        public ?string $failureReason = null,
        public array $providerPayload = [],
    ) {
    }
}
