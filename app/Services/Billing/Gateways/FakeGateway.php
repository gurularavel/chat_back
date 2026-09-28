<?php

namespace App\Services\Billing\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Local development / test gateway. The "hosted page" is a tiny signed page
 * (FakeCheckoutController) with Pay / Decline buttons.
 * A stored card token "fail" makes merchant-initiated charges fail.
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createOrder(Payment $payment, string $description, string $returnUrl, bool $saveCard): GatewayOrder
    {
        $orderId = 'fake_'.Str::random(16);
        Cache::put("fake_gateway:{$orderId}", ['status' => 'pending', 'return_url' => $returnUrl], now()->addDay());

        $url = URL::temporarySignedRoute('billing.fake-checkout', now()->addHour(), ['order' => $orderId]);

        return new GatewayOrder($orderId, $url);
    }

    public function fetchStatus(Payment $payment): GatewayResult
    {
        $state = Cache::get("fake_gateway:{$payment->gateway_order_id}", ['status' => 'pending']);

        return match ($state['status']) {
            'paid' => new GatewayResult(PaymentStatus::Paid, cardToken: 'tok_'.$payment->gateway_order_id, maskedPan: '416973******1234', brand: 'VISA', expiry: '12/30', orderId: $payment->gateway_order_id),
            'declined' => new GatewayResult(PaymentStatus::Failed, orderId: $payment->gateway_order_id, error: 'Declined'),
            default => new GatewayResult(PaymentStatus::Pending, orderId: $payment->gateway_order_id),
        };
    }

    public function chargeSavedCard(Payment $payment, PaymentMethod $method, string $description): GatewayResult
    {
        $orderId = 'fake_'.Str::random(16);

        return $method->token === 'fail'
            ? new GatewayResult(PaymentStatus::Failed, orderId: $orderId, error: 'Insufficient funds')
            : new GatewayResult(PaymentStatus::Paid, orderId: $orderId);
    }

    public function refund(Payment $payment, ?string $amount = null): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Refunded, orderId: $payment->gateway_order_id);
    }

    /** Used by the fake checkout page. */
    public static function complete(string $orderId, bool $paid): ?string
    {
        $state = Cache::get("fake_gateway:{$orderId}");
        if (! $state) {
            return null;
        }
        $state['status'] = $paid ? 'paid' : 'declined';
        Cache::put("fake_gateway:{$orderId}", $state, now()->addDay());

        return $state['return_url'];
    }
}
