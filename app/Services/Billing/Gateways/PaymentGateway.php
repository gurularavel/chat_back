<?php

namespace App\Services\Billing\Gateways;

use App\Models\Payment;
use App\Models\PaymentMethod;

interface PaymentGateway
{
    public function name(): string;

    /** Create a hosted-payment-page order; the customer is redirected to the returned URL. */
    public function createOrder(Payment $payment, string $description, string $returnUrl, bool $saveCard): GatewayOrder;

    /** Ask the bank for the real status (never trust redirect/callback params alone). */
    public function fetchStatus(Payment $payment): GatewayResult;

    /** Merchant-initiated charge of a stored card (renewals, seat upgrades). */
    public function chargeSavedCard(Payment $payment, PaymentMethod $method, string $description): GatewayResult;

    public function refund(Payment $payment, ?string $amount = null): GatewayResult;
}
