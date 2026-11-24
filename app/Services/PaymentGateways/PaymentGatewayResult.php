<?php

namespace App\Services\PaymentGateways;

use Illuminate\Support\Carbon;

final readonly class PaymentGatewayResult
{
    /**
     * @param array<string, mixed> $providerPayload
     */
    public function __construct(
        public string $providerReference,
        public ?string $paymentUrl,
        public ?Carbon $expiresAt,
        public array $providerPayload = [],
    ) {
    }
}
