<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\KnowledgeDocument;
use App\Models\Subscription;
use App\Models\Widget;
use App\Models\Workspace;

/**
 * Central place for plan/seat limit checks. Null limit = unlimited.
 */
class PlanLimits
{
    public function subscription(Workspace $workspace): ?Subscription
    {
        return Subscription::withoutGlobalScopes()->with('plan')->where('workspace_id', $workspace->id)->first();
    }

    /** Widget answers, AI and inbox work only while this is true. */
    public function isServiceActive(Workspace $workspace): bool
    {
        if ($workspace->isSuspended()) {
            return false;
        }

        $subscription = $this->subscription($workspace);
        if (! $subscription || ! $subscription->isUsable()) {
            return false;
        }

        // An unpaid trial ends at the period end even before the renew command runs.
        if ($subscription->status === SubscriptionStatus::Trialing) {
            return $subscription->current_period_end?->isFuture() ?? false;
        }

        return true;
    }

    public function hasFeature(Workspace $workspace, string $feature): bool
    {
        return in_array($feature, $this->subscription($workspace)?->plan?->features ?? [], true);
    }

    /** First-response target in minutes; null when the plan has no SLA or the workspace set no target. */
    public function slaMinutes(Workspace $workspace): ?int
    {
        return $workspace->sla_first_response_minutes && $this->hasFeature($workspace, 'sla')
            ? $workspace->sla_first_response_minutes
            : null;
    }

    public function usedSeats(Workspace $workspace): int
    {
        $members = $workspace->members()->count();
        $pendingInvites = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->count();

        return $members + $pendingInvites;
    }

    public function ensureSeatAvailable(Workspace $workspace): void
    {
        $seats = $this->subscription($workspace)?->seats ?? 1;

        if ($this->usedSeats($workspace) >= $seats) {
            throw new LimitExceededException('seats', $seats);
        }
    }

    public function ensureCanCreateWidget(Workspace $workspace): void
    {
        $this->ensureUnder($workspace, 'widgets', Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function ensureCanUploadDocument(Workspace $workspace, int $bytes): void
    {
        $plan = $this->subscription($workspace)?->plan;

        $this->ensureUnder($workspace, 'documents', KnowledgeDocument::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $maxMb = $plan?->limit('max_pdf_mb');
        if ($maxMb !== null && $bytes > $maxMb * 1024 * 1024) {
            throw new LimitExceededException('max_pdf_mb', $maxMb);
        }

        $totalMb = $plan?->limit('total_storage_mb');
        if ($totalMb !== null) {
            $used = (int) KnowledgeDocument::withoutGlobalScopes()->where('workspace_id', $workspace->id)->sum('size');
            if ($used + $bytes > $totalMb * 1024 * 1024) {
                throw new LimitExceededException('total_storage_mb', $totalMb);
            }
        }
    }

    public function conversationsThisMonth(Workspace $workspace): int
    {
        return Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function canStartConversation(Workspace $workspace): bool
    {
        $max = $this->subscription($workspace)?->plan?->limit('conversations_per_month');

        return $max === null || $this->conversationsThisMonth($workspace) < $max;
    }

    public function usage(Workspace $workspace): array
    {
        $subscription = $this->subscription($workspace);

        return [
            'seats' => ['used' => $workspace->members()->count(), 'max' => $subscription?->seats],
            'widgets' => ['used' => Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(), 'max' => $subscription?->plan?->limit('widgets')],
            'documents' => ['used' => KnowledgeDocument::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(), 'max' => $subscription?->plan?->limit('documents')],
            'storage_mb' => [
                'used' => round(KnowledgeDocument::withoutGlobalScopes()->where('workspace_id', $workspace->id)->sum('size') / 1048576, 1),
                'max' => $subscription?->plan?->limit('total_storage_mb'),
            ],
            'conversations_this_month' => ['used' => $this->conversationsThisMonth($workspace), 'max' => $subscription?->plan?->limit('conversations_per_month')],
        ];
    }

    private function ensureUnder(Workspace $workspace, string $key, int $current): void
    {
        $max = $this->subscription($workspace)?->plan?->limit($key);

        if ($max !== null && $current >= $max) {
            throw new LimitExceededException($key, $max);
        }
    }
}
