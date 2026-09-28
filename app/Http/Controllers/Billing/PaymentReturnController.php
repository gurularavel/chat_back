<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The bank redirects the customer here. Status is always re-checked with the
 * bank server-side before anything is applied; then we bounce to the frontend.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(Request $request, BillingService $billing): RedirectResponse
    {
        $frontend = rtrim(config('chat.frontend_url'), '/').'/dashboard/billing';
        $payment = Payment::withoutGlobalScopes()->find((int) $request->query('payment'));

        if (! $payment) {
            return redirect()->away($frontend.'?payment=missing');
        }

        try {
            $payment = $billing->confirm($payment);
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->away($frontend.'?payment='.$payment->id.'&status='.$payment->status->value);
    }
}
