<?php

namespace App\Services\Billing\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kapital Bank e-commerce (hosted payment page) driver.
 *
 * Flow: POST /order → redirect customer to hppUrl?id=&password= → customer returns →
 * GET /order/{id} to confirm status and read the stored card token (card-on-file).
 * Renewals: create a server-initiated order and execute a transaction with the stored token.
 *
 * NOTE: field names follow Kapital Bank's public e-commerce API. Verify them against the
 * documentation / test merchant you receive from the bank before going live
 * (search for "VERIFY" below).
 */
class KapitalBankGateway implements PaymentGateway
{
    public function __construct(private array $config) {}

    public function name(): string
    {
        return 'kapitalbank';
    }

    public function createOrder(Payment $payment, string $description, string $returnUrl, bool $saveCard): GatewayOrder
    {
        $order = [
            'typeRid' => 'Order_SMS',
            'amount' => $this->amount($payment->amount),
            'currency' => $payment->currency,
            'language' => $this->config['language'] ?? 'az',
            'title' => mb_substr($description, 0, 50),
            'description' => mb_substr($description, 0, 125),
            'hppRedirectUrl' => $returnUrl,
        ];
        if ($saveCard) {
            // VERIFY: card-on-file capture purposes for recurring billing
            $order['hppCofCapturePurposes'] = ['Cit', 'Recurring'];
        }

        $response = $this->client()->post('/order', ['order' => $order])->throw()->json('order');

        if (empty($response['id']) || empty($response['hppUrl'])) {
            throw new RuntimeException('Kapital Bank: unexpected create-order response.');
        }

        $redirect = $response['hppUrl'].'?'.http_build_query(['id' => $response['id'], 'password' => $response['password'] ?? '']);

        return new GatewayOrder((string) $response['id'], $redirect, [
            'password' => $response['password'] ?? null,
            'status' => $response['status'] ?? null,
        ]);
    }

    public function fetchStatus(Payment $payment): GatewayResult
    {
        $order = $this->client()
            ->get('/order/'.$payment->gateway_order_id, [
                'password' => $payment->raw['password'] ?? null,
                'tokenDetailLevel' => 2,
                'tranDetailLevel' => 2,
            ])
            ->throw()
            ->json('order') ?? [];

        $token = $order['storedTokens'][0] ?? null; // VERIFY

        return new GatewayResult(
            status: $this->mapStatus($order['status'] ?? ''),
            cardToken: isset($token['id']) ? (string) $token['id'] : null,
            maskedPan: $token['displayName'] ?? ($order['srcToken']['displayName'] ?? null),
            brand: $token['paymentMethod'] ?? null,
            expiry: $token['expiration'] ?? null,
            orderId: (string) ($order['id'] ?? $payment->gateway_order_id),
            raw: $order,
        );
    }

    public function chargeSavedCard(Payment $payment, PaymentMethod $method, string $description): GatewayResult
    {
        // VERIFY: merchant-initiated recurring order + transaction execution
        $order = $this->client()->post('/order', ['order' => [
            'typeRid' => 'Order_REC',
            'amount' => $this->amount($payment->amount),
            'currency' => $payment->currency,
            'language' => $this->config['language'] ?? 'az',
            'description' => mb_substr($description, 0, 125),
            'initiationEnvKind' => 'Server',
        ]])->throw()->json('order');

        $orderId = (string) $order['id'];
        $password = $order['password'] ?? null;

        $tran = $this->client()->post("/order/{$orderId}/set-src-token?password={$password}", [
            'order' => ['initiationEnvKind' => 'Server'],
            'token' => ['storedId' => (int) $method->token],
        ])->throw();

        $exec = $this->client()->post("/order/{$orderId}/exec-tran?password={$password}", [
            'tran' => [
                'phase' => 'Single',
                'amount' => $this->amount($payment->amount),
                'conditions' => ['cofUsage' => 'Recurring'],
            ],
        ]);

        $status = PaymentStatus::Failed;
        if ($exec->successful()) {
            $confirmed = $this->client()->get("/order/{$orderId}", ['password' => $password])->json('order.status') ?? '';
            $status = $this->mapStatus($confirmed);
        }

        return new GatewayResult(
            status: $status,
            orderId: $orderId,
            raw: ['password' => $password, 'exec' => $exec->json(), 'token' => $tran->json()],
            error: $exec->successful() ? null : $exec->body(),
        );
    }

    public function refund(Payment $payment, ?string $amount = null): GatewayResult
    {
        $password = $payment->raw['password'] ?? null;

        $response = $this->client()->post("/order/{$payment->gateway_order_id}/exec-tran?password={$password}", [
            'tran' => [
                'phase' => 'Single',
                'type' => 'Refund',
                'amount' => $this->amount($amount ?? $payment->amount),
            ],
        ]);

        return new GatewayResult(
            status: $response->successful() ? PaymentStatus::Refunded : PaymentStatus::Paid,
            orderId: $payment->gateway_order_id,
            raw: $response->json() ?? [],
            error: $response->successful() ? null : $response->body(),
        );
    }

    private function mapStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'FullyPaid', 'Funded', 'Closed' => PaymentStatus::Paid,
            'Refunded', 'Reversed' => PaymentStatus::Refunded,
            'Declined', 'Cancelled', 'Expired', 'Rejected' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    private function amount(string|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function client(): PendingRequest
    {
        if (empty($this->config['username']) || empty($this->config['password'])) {
            throw new RuntimeException('Kapital Bank credentials are not configured.');
        }

        return Http::baseUrl(rtrim($this->config['base_url'], '/'))
            ->withBasicAuth($this->config['username'], $this->config['password'])
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
