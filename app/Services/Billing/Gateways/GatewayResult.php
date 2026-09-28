<?php

namespace App\Services\Billing\Gateways;

use App\Enums\PaymentStatus;

final readonly class GatewayResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $cardToken = null,
        public ?string $maskedPan = null,
        public ?string $brand = null,
        public ?string $expiry = null,
        public ?string $orderId = null,
        public array $raw = [],
        public ?string $error = null,
    ) {}

    public function paid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }
}
