<?php

namespace App\Services\Billing\Gateways;

final readonly class GatewayOrder
{
    public function __construct(
        public string $orderId,
        public string $redirectUrl,
        public array $raw = [],
    ) {}
}
