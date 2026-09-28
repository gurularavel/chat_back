<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\Gateways\FakeGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Hosted payment page stand-in for local development (PAYMENT_GATEWAY=fake). */
class FakeCheckoutController extends Controller
{
    public function show(Request $request, string $order): Response
    {
        abort_if(app()->isProduction(), 404);

        $pay = e(url()->signedRoute('billing.fake-checkout.complete', ['order' => $order, 'result' => 'paid']));
        $decline = e(url()->signedRoute('billing.fake-checkout.complete', ['order' => $order, 'result' => 'declined']));

        return response(<<<HTML
            <!doctype html><html><head><meta charset="utf-8"><title>Test payment</title>
            <style>body{font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;background:#f4f4f5}
            .card{background:#fff;padding:32px;border-radius:12px;box-shadow:0 2px 12px #0001;text-align:center}
            a{display:inline-block;margin:8px;padding:10px 18px;border-radius:8px;text-decoration:none;color:#fff}</style></head>
            <body><div class="card"><h2>Test payment gateway</h2><p>Order {$order}</p>
            <a href="{$pay}" style="background:#16a34a">Pay</a><a href="{$decline}" style="background:#dc2626">Decline</a></div></body></html>
            HTML);
    }

    public function complete(string $order, string $result): RedirectResponse
    {
        abort_if(app()->isProduction(), 404);
        $returnUrl = FakeGateway::complete($order, $result === 'paid') ?? abort(404);

        return redirect()->away($returnUrl);
    }
}
