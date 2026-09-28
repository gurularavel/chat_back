<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'workspace_id' => ['nullable', 'integer']]);

        return response()->json(
            Payment::with('workspace:id,name')
                ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->input('workspace_id'), fn ($q, $id) => $q->where('workspace_id', $id))
                ->latest()
                ->paginate(50)
        );
    }

    public function refund(Payment $payment, BillingService $billing): JsonResponse
    {
        if ($payment->status !== PaymentStatus::Paid) {
            abort(422, 'Only paid payments can be refunded.');
        }

        $ok = $billing->refund($payment);
        AuditLog::record('admin.payment.refund', $payment, ['ok' => $ok], $payment->workspace_id);

        return response()->json(['refunded' => $ok], $ok ? 200 : 422);
    }
}
