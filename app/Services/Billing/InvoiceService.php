<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Notifications\InvoiceNotification;
use App\Support\Branding;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invoices: open ones issued ahead of a renewal (the "time to pay" notice),
 * and paid ones for every successful payment. Seller and buyer details are
 * snapshotted so an issued invoice never changes when settings do.
 */
class InvoiceService
{
    /**
     * Open invoice for the subscription's next period. Idempotent: an existing open
     * invoice for that period is returned, re-priced if seats or plan changed since.
     */
    public function issueRenewal(Subscription $subscription): ?Invoice
    {
        $subscription->loadMissing(['plan', 'workspace']);

        if ($subscription->status === SubscriptionStatus::Trialing || $subscription->cancel_at_period_end || ! $subscription->current_period_end) {
            return null;
        }

        $plan = $subscription->plan;
        $seats = $subscription->pending_seats ?? $subscription->seats;
        $start = $subscription->current_period_end;
        $end = $plan->interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonth();
        $attributes = [
            'type' => PaymentType::Renewal,
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => $start,
            'currency' => $plan->currency,
            'lines' => [$this->line($subscription->workspace, $plan->localizedName($subscription->workspace->locale), $plan->interval, $seats, $plan->price_per_seat, $plan->priceFor($seats), $start, $end)],
        ] + $this->amounts($plan->priceFor($seats));

        $existing = Invoice::withoutGlobalScopes()->open()
            ->where('subscription_id', $subscription->id)
            ->where('type', PaymentType::Renewal)
            ->latest('id')
            ->first();

        if ($existing && $existing->period_start?->equalTo($start)) {
            if ($existing->total !== $attributes['total'] || $existing->lines !== $attributes['lines']) {
                $existing->update($attributes);
            }

            return $existing;
        }

        // A stale open invoice for an older period (e.g. the plan was changed) is replaced.
        $existing?->update(['status' => InvoiceStatus::Void]);

        return $this->create($subscription->workspace, $attributes + [
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Open,
        ]);
    }

    /**
     * A payment succeeded: settle the invoice it was for, or issue a paid one.
     * Runs inside BillingService::applyResult's transaction.
     */
    public function settle(Payment $payment, Subscription $subscription): Invoice
    {
        $invoice = null;
        if ($id = $payment->payload['invoice_id'] ?? null) {
            $invoice = Invoice::withoutGlobalScopes()->whereKey($id)->where('workspace_id', $payment->workspace_id)->first();
        } elseif ($payment->type === PaymentType::Renewal) {
            $invoice = Invoice::withoutGlobalScopes()->open()->where('subscription_id', $subscription->id)->where('type', PaymentType::Renewal)->latest('id')->first();
        }

        if ($invoice) {
            // The period actually paid for: differs from the issued one when a lapsed subscription is paid late.
            $lines = $invoice->lines;
            if ($subscription->current_period_start && isset($lines[0])) {
                $lines[0]['period_start'] = $subscription->current_period_start->toDateString();
                $lines[0]['period_end'] = $subscription->current_period_end->toDateString();
            }

            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'payment_id' => $payment->id,
                'paid_at' => $payment->paid_at ?? now(),
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'lines' => $lines,
            ] + $this->amounts($payment->amount)); // what was actually charged wins

            return $invoice;
        }

        return $this->create($subscription->workspace()->first(), $this->paidAttributes($payment, $subscription));
    }

    /** Open invoices of a subscription are cancelled (plan switched, subscription cancelled). */
    public function voidOpen(Subscription $subscription, ?int $exceptId = null): void
    {
        Invoice::withoutGlobalScopes()->open()
            ->where('subscription_id', $subscription->id)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->update(['status' => InvoiceStatus::Void]);
    }

    private function paidAttributes(Payment $payment, Subscription $subscription): array
    {
        $plan = $subscription->plan()->first();
        $workspace = $subscription->workspace()->first();
        $seats = (int) $payment->payload['seats'];
        $name = $plan->localizedName($workspace->locale);

        if ($payment->type === PaymentType::SeatUpgrade) {
            $extra = max(1, $seats - (int) ($payment->payload['previous_seats'] ?? $seats - 1));
            $start = now();
            $end = $subscription->current_period_end ?? now();
            $line = $this->line($workspace, $name, 'prorated', $extra, $plan->price_per_seat, $payment->amount, $start, $end);
        } else {
            $start = $subscription->current_period_start ?? now();
            $end = $subscription->current_period_end ?? now();
            $line = $this->line($workspace, $name, $plan->interval, $seats, $plan->price_per_seat, $payment->amount, $start, $end);
        }

        return [
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Paid,
            'type' => $payment->type,
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => $payment->paid_at ?? now(),
            'paid_at' => $payment->paid_at ?? now(),
            'currency' => $payment->currency,
            'lines' => [$line],
        ] + $this->amounts($payment->amount);
    }

    private function create(Workspace $workspace, array $attributes): Invoice
    {
        return DB::transaction(function () use ($workspace, $attributes) {
            $invoice = Invoice::create($attributes + [
                'workspace_id' => $workspace->id,
                'number' => 'TMP-'.Str::uuid(),
                'seller' => $this->seller(),
                'buyer' => $this->buyer($workspace),
            ]);
            $invoice->update(['number' => sprintf('INV-%s-%06d', now()->format('Y'), $invoice->id)]);

            return $invoice;
        });
    }

    /** @return array{description: string, kind: string, seats: int, unit_price: string, amount: string, period_start: string, period_end: string} */
    private function line(Workspace $workspace, string $planName, string $kind, int $seats, string $unitPrice, string $amount, CarbonInterface $start, CarbonInterface $end): array
    {
        return [
            'description' => __('billing.invoice_line.'.$kind, ['plan' => $planName], $workspace->locale),
            'kind' => $kind, // month | year | prorated
            'seats' => $seats,
            'unit_price' => number_format((float) $unitPrice, 2, '.', ''),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ];
    }

    /** Prices are VAT-inclusive; the VAT part is shown separately when a rate is configured. */
    private function amounts(string $total): array
    {
        $rate = (float) PlatformSetting::get('invoice_tax_rate', 0);
        $tax = $rate > 0 ? round((float) $total * $rate / (100 + $rate), 2) : 0.0;

        return [
            'total' => number_format((float) $total, 2, '.', ''),
            'tax_rate' => number_format($rate, 2, '.', ''),
            'tax' => number_format($tax, 2, '.', ''),
            'subtotal' => number_format((float) $total - $tax, 2, '.', ''),
        ];
    }

    private function seller(): array
    {
        $branding = Branding::toArray();

        return [
            'name' => PlatformSetting::get('invoice_company', $branding['name']),
            'tax_id' => PlatformSetting::get('invoice_tax_id'),
            'address' => PlatformSetting::get('invoice_address'),
            'email' => PlatformSetting::get('invoice_email', config('mail.from.address')),
            'phone' => PlatformSetting::get('invoice_phone'),
            'bank' => PlatformSetting::get('invoice_bank'),
            'website' => $branding['url'],
            'logo_url' => Branding::fullLogoUrl(),
        ];
    }

    /**
     * Email the workspace owner (and the billing email, if set) in the workspace language.
     * A $reminderKey makes the email one-off per invoice (scheduler reruns send nothing new).
     */
    public function notify(Invoice $invoice, string $stage, ?string $reminderKey = null, ?Carbon $graceEndsAt = null): void
    {
        if ($reminderKey !== null) {
            $sent = $invoice->reminders ?? [];
            if (in_array($reminderKey, $sent, true)) {
                return;
            }
            $invoice->update(['reminders' => [...$sent, $reminderKey]]);
        }

        $workspace = Workspace::withoutGlobalScopes()->with('owner')->find($invoice->workspace_id);
        $emails = array_values(array_unique(array_filter([$workspace->billing_email, $workspace->owner?->email])));
        if ($emails === []) {
            return;
        }

        $subscription = $invoice->subscription()->withoutGlobalScopes()->with('paymentMethod')->first();
        $card = $subscription?->paymentMethod?->masked_pan;

        Notification::route('mail', $emails)->notify(
            (new InvoiceNotification($invoice, $stage, $card, $graceEndsAt))->locale($workspace->locale),
        );
    }

    public function buyer(Workspace $workspace): array
    {
        return [
            'name' => $workspace->billing_name ?: $workspace->name,
            'email' => $workspace->billing_email ?: $workspace->owner()->value('email'),
            'tax_id' => $workspace->billing_tax_id,
            'address' => $workspace->billing_address,
        ];
    }
}
