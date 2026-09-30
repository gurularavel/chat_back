<?php

namespace App\Console\Commands;

use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\InvoiceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:remind')]
#[Description('Issue renewal invoices ahead of the due date and email payment reminders')]
class SendBillingReminders extends Command
{
    public function handle(InvoiceService $invoices): int
    {
        $issued = 0;
        $reminded = 0;
        $leadDays = config('payments.invoice_lead_days');

        // 1. "Time to pay" invoices, issued monthly/yearly lead days before the period ends.
        Subscription::withoutGlobalScopes()
            ->with(['plan', 'workspace'])
            ->where('status', SubscriptionStatus::Active)
            ->where('cancel_at_period_end', false)
            ->where('is_complimentary', false)
            ->where('current_period_end', '>', now())
            ->where('current_period_end', '<=', now()->addDays(max($leadDays)))
            ->each(function (Subscription $subscription) use ($invoices, $leadDays, &$issued) {
                $lead = $leadDays[$subscription->interval] ?? $leadDays['month'];
                if ($subscription->current_period_end->gt(now()->addDays($lead))) {
                    return;
                }

                try {
                    $invoice = $invoices->issueRenewal($subscription);
                    if ($invoice && ! in_array('issued', $invoice->reminders ?? [], true)) {
                        $invoices->notify($invoice, 'issued', 'issued');
                        $issued++;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $this->error("subscription #{$subscription->id}: {$e->getMessage()}");
                }
            });

        // 2. Reminders for invoices the customer has to pay by hand (no saved card to charge).
        Invoice::withoutGlobalScopes()->open()
            ->where('type', PaymentType::Renewal)
            ->where('due_at', '>=', now()->startOfDay())
            ->whereHas('subscription', fn ($q) => $q->withoutGlobalScopes()->where('status', SubscriptionStatus::Active)->whereNull('payment_method_id'))
            ->each(function (Invoice $invoice) use ($invoices, &$reminded) {
                $daysLeft = (int) now()->startOfDay()->diffInDays($invoice->due_at->copy()->startOfDay());

                // The "issued" email of today already says when it is due.
                if ($daysLeft > 0 && $invoice->created_at->isToday()) {
                    return;
                }

                if ($daysLeft === 0) {
                    $invoices->notify($invoice, 'due', 'due');
                    $reminded++;
                } elseif (in_array($daysLeft, config('payments.reminder_days'), true)) {
                    $invoices->notify($invoice, 'reminder', 'reminder-'.$daysLeft);
                    $reminded++;
                }
            });

        $this->info("Issued {$issued} invoice(s), sent {$reminded} reminder(s).");

        return self::SUCCESS;
    }
}
