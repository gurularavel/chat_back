<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing, private PlanLimits $limits) {}

    /** Public pricing list. */
    public function plans(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::where('is_active', true)->where('is_trial_plan', false)->orderBy('sort')->get());
    }

    public function show(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $subscription = $this->limits->subscription($workspace)?->load(['plan', 'paymentMethod']);

        return response()->json([
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'service_active' => $this->limits->isServiceActive($workspace),
            'usage' => $this->limits->usage($workspace),
            'payments' => Payment::latest()->limit(50)->get(['id', 'amount', 'currency', 'status', 'type', 'paid_at', 'created_at']),
            'invoices' => InvoiceResource::collection(Invoice::latest('id')->limit(50)->get())->resolve(),
            'open_invoice' => ($open = Invoice::open()->oldest('due_at')->first()) ? (new InvoiceResource($open))->resolve() : null,
            'billing_details' => $this->details($workspace),
        ]);
    }

    /** Customer's legal details printed on invoices ("Bill to"). */
    public function updateDetails(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'billing_name' => ['nullable', 'string', 'max:190'],
            'billing_email' => ['nullable', 'email', 'max:190'],
            'billing_tax_id' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['nullable', 'string', 'max:500'],
        ]);

        $workspace->update($data);
        AuditLog::record('billing.details_updated', $workspace);

        return response()->json($this->details($workspace));
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'seats' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $plan = Plan::where('is_active', true)->where('is_trial_plan', false)->findOrFail($data['plan_id']);

        $url = $this->billing->checkout($request->attributes->get('workspace'), $plan, $data['seats'], route('billing.return'));
        AuditLog::record('billing.checkout', $plan, ['seats' => $data['seats']]);

        return response()->json(['redirect_url' => $url]);
    }

    public function seats(Request $request): JsonResponse
    {
        $data = $request->validate(['seats' => ['required', 'integer', 'min:1', 'max:1000']]);
        $result = $this->billing->changeSeats($request->attributes->get('workspace'), $data['seats'], route('billing.return'));
        AuditLog::record('billing.seats_changed', null, ['seats' => $data['seats'], 'result' => $result['status']]);

        return response()->json($result);
    }

    public function cancel(Request $request): JsonResponse
    {
        $subscription = $this->limits->subscription($request->attributes->get('workspace')) ?? abort(404);
        $this->billing->cancel($subscription);
        AuditLog::record('billing.canceled', $subscription);

        return response()->json(['ok' => true]);
    }

    public function resume(Request $request): JsonResponse
    {
        $subscription = $this->limits->subscription($request->attributes->get('workspace')) ?? abort(404);
        $this->billing->resume($subscription);

        return response()->json(['ok' => true]);
    }

    public function invoice(Invoice $invoice): InvoiceResource
    {
        return (new InvoiceResource($invoice->load('payment')))->detailed();
    }

    /** Pay an open invoice on the bank's hosted page. */
    public function payInvoice(Invoice $invoice): JsonResponse
    {
        $url = $this->billing->payInvoice($invoice, route('billing.return'));
        AuditLog::record('billing.invoice_pay', $invoice);

        return response()->json(['redirect_url' => $url]);
    }

    private function details(Workspace $workspace): array
    {
        return $workspace->only(['billing_name', 'billing_email', 'billing_tax_id', 'billing_address']) + [
            // Used on invoices while the fields above are empty.
            'defaults' => ['billing_name' => $workspace->name, 'billing_email' => $workspace->owner()->value('email')],
        ];
    }

    /** Frontend polls this after returning from the bank. */
    public function payment(Payment $payment): JsonResponse
    {
        $payment = $this->billing->confirm($payment);

        return response()->json(['id' => $payment->id, 'status' => $payment->status->value, 'type' => $payment->type->value]);
    }
}
