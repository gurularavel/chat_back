<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Gateways\GatewayResult;
use App\Services\Billing\Gateways\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Per-seat subscriptions: checkout, seat changes with proration,
 * renewals with retries, invoices.
 */
class BillingService
{
    public function __construct(
        private PaymentGateway $gateway,
        private PlanLimits $limits,
        private InvoiceService $invoices,
    ) {}

    /**
     * Start paying for a plan (first payment, or switching plan). Returns the hosted page URL.
     */
    public function checkout(Workspace $workspace, Plan $plan, int $seats, string $interval, string $returnUrl): string
    {
        $this->validateSeats($workspace, $plan, $seats);
        if (! in_array($interval, $plan->intervals(), true)) {
            throw ValidationException::withMessages(['interval' => __('billing.interval_unavailable')]);
        }

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $this->limits->subscription($workspace)?->id,
            'gateway' => $this->gateway->name(),
            'amount' => $plan->priceFor($seats, $interval),
            'currency' => $plan->currency,
            'status' => PaymentStatus::Pending,
            'type' => PaymentType::Initial,
            'payload' => ['plan_id' => $plan->id, 'seats' => $seats, 'interval' => $interval],
        ]);

        $order = $this->gateway->createOrder(
            $payment,
            sprintf('%s — %s × %d', config('app.name'), $plan->localizedName(), $seats),
            $this->returnUrl($returnUrl, $payment),
            saveCard: true,
        );

        $payment->update(['gateway_order_id' => $order->orderId, 'raw' => $order->raw]);

        return $order->redirectUrl;
    }

    /**
     * Change seat count on an active paid subscription.
     * Increase: prorated charge now (stored card, else hosted page). Decrease: from next period.
     *
     * @return array{status: 'applied'|'scheduled'|'redirect', redirect_url?: string}
     */
    public function changeSeats(Workspace $workspace, int $seats, string $returnUrl): array
    {
        $subscription = $this->limits->subscription($workspace);
        if (! $subscription || $subscription->status !== SubscriptionStatus::Active) {
            throw ValidationException::withMessages(['seats' => __('billing.needs_active_subscription')]);
        }
        $this->validateSeats($workspace, $subscription->plan, $seats);

        if ($seats < $subscription->seats) {
            $subscription->update(['pending_seats' => $seats]);

            return ['status' => 'scheduled'];
        }
        if ($seats === $subscription->seats) {
            $subscription->update(['pending_seats' => null]);

            return ['status' => 'applied'];
        }

        $amount = $this->prorate($subscription, $seats - $subscription->seats);
        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $subscription->id,
            'gateway' => $this->gateway->name(),
            'amount' => $amount,
            'currency' => $subscription->plan->currency,
            'status' => PaymentStatus::Pending,
            'type' => PaymentType::SeatUpgrade,
            'payload' => ['plan_id' => $subscription->plan_id, 'seats' => $seats, 'interval' => $subscription->interval, 'previous_seats' => $subscription->seats],
        ]);
        $description = sprintf('%s — +%d seat', config('app.name'), $seats - $subscription->seats);

        if ($subscription->paymentMethod) {
            $result = $this->gateway->chargeSavedCard($payment, $subscription->paymentMethod, $description);
            $this->applyResult($payment, $result);

            if (! $result->paid()) {
                throw ValidationException::withMessages(['seats' => __('billing.charge_failed')]);
            }

            return ['status' => 'applied'];
        }

        $order = $this->gateway->createOrder($payment, $description, $this->returnUrl($returnUrl, $payment), saveCard: true);
        $payment->update(['gateway_order_id' => $order->orderId, 'raw' => $order->raw]);

        return ['status' => 'redirect', 'redirect_url' => $order->redirectUrl];
    }

    /** Customer came back from the hosted page (or callback arrived): confirm with the bank. */
    public function confirm(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        $this->applyResult($payment, $this->gateway->fetchStatus($payment));

        return $payment->fresh();
    }

    /** Called by the scheduler for due subscriptions. */
    public function renew(Subscription $subscription): void
    {
        $subscription->loadMissing(['plan', 'paymentMethod', 'workspace']);

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $subscription->update(['status' => SubscriptionStatus::Expired]);
            AuditLog::record('subscription.trial_expired', $subscription, workspaceId: $subscription->workspace_id);

            return;
        }

        if ($subscription->cancel_at_period_end) {
            $subscription->update(['status' => SubscriptionStatus::Expired]);
            AuditLog::record('subscription.canceled_expired', $subscription, workspaceId: $subscription->workspace_id);

            return;
        }

        // A plan given for free is never charged; the customer buys one to continue.
        if ($subscription->is_complimentary) {
            $subscription->update(['status' => SubscriptionStatus::Expired]);
            AuditLog::record('subscription.grant_expired', $subscription, workspaceId: $subscription->workspace_id);

            return;
        }

        $seats = $subscription->pending_seats ?? $subscription->seats;
        $invoice = $this->invoices->issueRenewal($subscription);
        $payment = Payment::create([
            'workspace_id' => $subscription->workspace_id,
            'subscription_id' => $subscription->id,
            'gateway' => $this->gateway->name(),
            'amount' => $subscription->plan->priceFor($seats, $subscription->interval),
            'currency' => $subscription->plan->currency,
            'status' => PaymentStatus::Pending,
            'type' => PaymentType::Renewal,
            'payload' => ['plan_id' => $subscription->plan_id, 'seats' => $seats, 'interval' => $subscription->interval, 'invoice_id' => $invoice?->id],
        ]);

        $result = $subscription->paymentMethod
            ? $this->gateway->chargeSavedCard($payment, $subscription->paymentMethod, config('app.name').' — renewal')
            : new GatewayResult(PaymentStatus::Failed, error: 'No stored card');

        $this->applyResult($payment, $result);

        if (! $result->paid()) {
            $subscription = $this->registerFailedRenewal($subscription->fresh());

            if ($invoice && $subscription->status === SubscriptionStatus::PastDue) {
                $graceEnds = $subscription->current_period_end?->copy()->addDays(config('payments.grace_days'));
                $this->invoices->notify($invoice->fresh(), 'failed', 'failed-'.$subscription->renewal_attempts, $graceEnds);
            }
        }
    }

    /**
     * Customer pays an open invoice from the billing page (or the email link) on the
     * bank's hosted page. Paying early extends the subscription from the current period end;
     * paying an expired subscription's invoice reactivates it.
     */
    public function payInvoice(Invoice $invoice, string $returnUrl): string
    {
        $subscription = $invoice->subscription()->withoutGlobalScopes()->with('plan')->first();
        if (! $invoice->isOpen() || ! $subscription) {
            throw ValidationException::withMessages(['invoice' => __('billing.invoice_not_payable')]);
        }

        $payment = Payment::create([
            'workspace_id' => $invoice->workspace_id,
            'subscription_id' => $subscription->id,
            'gateway' => $this->gateway->name(),
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => PaymentStatus::Pending,
            'type' => PaymentType::Renewal,
            'payload' => [
                'plan_id' => $subscription->plan_id,
                'seats' => (int) ($invoice->lines[0]['seats'] ?? $subscription->seats),
                'interval' => $subscription->interval,
                'invoice_id' => $invoice->id,
            ],
        ]);

        $order = $this->gateway->createOrder(
            $payment,
            sprintf('%s — %s', config('app.name'), $invoice->number),
            $this->returnUrl($returnUrl, $payment),
            saveCard: true,
        );
        $payment->update(['gateway_order_id' => $order->orderId, 'raw' => $order->raw]);

        return $order->redirectUrl;
    }

    /**
     * Superadmin gives a plan without payment, starting now for the given number of periods.
     * Replaces whatever the workspace had; recorded as a zero "grant" payment so it shows
     * up in the payments list, and the subscription is flagged as complimentary.
     */
    public function grant(Workspace $workspace, Plan $plan, int $seats, string $interval, int $periods, User $admin): Subscription
    {
        return DB::transaction(function () use ($workspace, $plan, $seats, $interval, $periods, $admin) {
            $subscription = Subscription::withoutGlobalScopes()->firstOrNew(['workspace_id' => $workspace->id]);
            if ($subscription->exists) {
                $this->invoices->voidOpen($subscription);
            }

            $start = now();
            $end = $start->copy();
            for ($i = 0; $i < $periods; $i++) {
                $end = $this->periodEnd($end, $interval);
            }

            $subscription->fill([
                'plan_id' => $plan->id,
                'seats' => $seats,
                'interval' => $interval,
                'pending_seats' => null,
                'status' => SubscriptionStatus::Active,
                'is_complimentary' => true,
                'granted_by' => $admin->id,
                'granted_at' => $start,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'cancel_at_period_end' => false,
                'renewal_attempts' => 0,
                'next_retry_at' => null,
            ])->save();

            Payment::create([
                'workspace_id' => $workspace->id,
                'subscription_id' => $subscription->id,
                'gateway' => 'manual',
                'amount' => 0,
                'currency' => $plan->currency,
                'status' => PaymentStatus::Paid,
                'type' => PaymentType::Grant,
                'payload' => ['plan_id' => $plan->id, 'seats' => $seats, 'interval' => $interval, 'periods' => $periods, 'granted_by' => $admin->id],
                'paid_at' => $start,
            ]);

            return $subscription;
        });
    }

    public function cancel(Subscription $subscription): void
    {
        $subscription->update(['cancel_at_period_end' => true]);
        $this->invoices->voidOpen($subscription);
    }

    public function resume(Subscription $subscription): void
    {
        $subscription->update(['cancel_at_period_end' => false]);
    }

    public function refund(Payment $payment): bool
    {
        $result = $this->gateway->refund($payment);
        if ($result->status === PaymentStatus::Refunded) {
            $payment->update(['status' => PaymentStatus::Refunded]);

            return true;
        }

        return false;
    }

    /** Idempotent: a payment's effect is applied exactly once. */
    public function applyResult(Payment $payment, GatewayResult $result): void
    {
        DB::transaction(function () use ($payment, $result) {
            $payment = Payment::withoutGlobalScopes()->lockForUpdate()->find($payment->id);
            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            $payment->update([
                'status' => $result->status,
                'gateway_order_id' => $payment->gateway_order_id ?? $result->orderId,
                'raw' => array_merge($payment->raw ?? [], $result->raw, $result->error ? ['error' => $result->error] : []),
                'paid_at' => $result->paid() ? now() : null,
            ]);

            if (! $result->paid()) {
                return;
            }

            $subscription = $this->applyToSubscription($payment, $result);
            $invoice = $this->invoices->settle($payment, $subscription);
            $this->invoices->notify($invoice, 'paid');
            AuditLog::record('payment.paid', $payment, ['amount' => $payment->amount, 'type' => $payment->type->value], $payment->workspace_id);
        });
    }

    public function prorate(Subscription $subscription, int $extraSeats): string
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;
        $total = max(1, $start->diffInSeconds($end));
        $remaining = max(0, now()->diffInSeconds($end, false));

        $amount = (float) $subscription->seatPrice() * $extraSeats * ($remaining / $total);

        return number_format(max($amount, 0.01), 2, '.', '');
    }

    private function applyToSubscription(Payment $payment, GatewayResult $result): Subscription
    {
        $plan = Plan::findOrFail($payment->payload['plan_id']);
        $seats = (int) $payment->payload['seats'];
        $subscription = Subscription::withoutGlobalScopes()->firstOrNew(['workspace_id' => $payment->workspace_id]);

        $interval = $payment->payload['interval'] ?? $subscription->interval ?? 'month';

        $attributes = ['plan_id' => $plan->id, 'seats' => $seats, 'interval' => $interval, 'status' => SubscriptionStatus::Active, 'renewal_attempts' => 0, 'next_retry_at' => null];

        switch ($payment->type) {
            case PaymentType::Initial:
                $attributes += [
                    'current_period_start' => now(),
                    'current_period_end' => $this->periodEnd(now(), $interval),
                    'pending_seats' => null,
                    'cancel_at_period_end' => false,
                    // Bought with money from now on, even if it was given for free before.
                    'is_complimentary' => false,
                    'granted_by' => null,
                    'granted_at' => null,
                ];
                if ($subscription->exists) {
                    // New plan, new period: a pending renewal invoice for the old one no longer applies.
                    $this->invoices->voidOpen($subscription);
                }
                break;
            case PaymentType::Renewal:
                $start = $subscription->current_period_end && $subscription->current_period_end->isFuture() ? $subscription->current_period_end : now();
                $attributes += ['current_period_start' => $start, 'current_period_end' => $this->periodEnd($start, $interval), 'pending_seats' => null];
                break;
            case PaymentType::SeatUpgrade:
                $attributes += ['pending_seats' => null];
                break;
        }

        if ($result->cardToken) {
            PaymentMethod::withoutGlobalScopes()->where('workspace_id', $payment->workspace_id)->update(['is_default' => false]);
            $method = PaymentMethod::create([
                'workspace_id' => $payment->workspace_id,
                'gateway' => $payment->gateway,
                'token' => $result->cardToken,
                'masked_pan' => $result->maskedPan,
                'brand' => $result->brand,
                'expiry' => $result->expiry,
                'is_default' => true,
            ]);
            $attributes['payment_method_id'] = $method->id;
        }

        $subscription->fill($attributes)->save();
        $payment->update(['subscription_id' => $subscription->id]);

        return $subscription;
    }

    private function registerFailedRenewal(Subscription $subscription): Subscription
    {
        $attempts = $subscription->renewal_attempts + 1;
        $graceOver = $subscription->current_period_end?->copy()->addDays(config('payments.grace_days'))->isPast() ?? true;

        if ($attempts >= config('payments.max_renewal_attempts') || $graceOver) {
            $subscription->update(['status' => SubscriptionStatus::Expired, 'renewal_attempts' => $attempts, 'next_retry_at' => null]);
            AuditLog::record('subscription.expired', $subscription, workspaceId: $subscription->workspace_id);

            return $subscription;
        }

        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'renewal_attempts' => $attempts,
            'next_retry_at' => now()->addDays(config('payments.retry_interval_days')),
        ]);
        Log::info('Renewal failed', ['subscription' => $subscription->id, 'attempt' => $attempts]);

        return $subscription;
    }

    private function validateSeats(Workspace $workspace, Plan $plan, int $seats): void
    {
        $members = $workspace->members()->count();

        if ($seats < max($plan->min_seats, 1) || ($plan->max_seats && $seats > $plan->max_seats)) {
            throw ValidationException::withMessages(['seats' => __('billing.invalid_seats', ['min' => $plan->min_seats, 'max' => $plan->max_seats ?? '∞'])]);
        }
        if ($seats < $members) {
            throw ValidationException::withMessages(['seats' => __('billing.seats_below_members', ['members' => $members])]);
        }
    }

    private function periodEnd(CarbonInterface $start, string $interval): CarbonInterface
    {
        return $interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonth();
    }

    private function returnUrl(string $base, Payment $payment): string
    {
        return $base.(str_contains($base, '?') ? '&' : '?').'payment='.$payment->id;
    }
}
