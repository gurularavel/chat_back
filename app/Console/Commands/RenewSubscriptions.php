<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:renew')]
#[Description('Charge due subscriptions, retry past-due ones and expire ended trials')]
class RenewSubscriptions extends Command
{
    public function handle(BillingService $billing): int
    {
        $due = Subscription::query()
            ->where(function ($q) {
                $q->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
                    ->where('current_period_end', '<=', now());
            })
            ->orWhere(function ($q) {
                $q->where('status', SubscriptionStatus::PastDue)
                    ->where('next_retry_at', '<=', now());
            })
            ->get();

        foreach ($due as $subscription) {
            try {
                $billing->renew($subscription);
                $this->line("#{$subscription->id}: ".$subscription->fresh()->status->value);
            } catch (Throwable $e) {
                report($e);
                $this->error("#{$subscription->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$due->count()} subscription(s).");

        return self::SUCCESS;
    }
}
