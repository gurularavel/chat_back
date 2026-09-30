<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $paying = Subscription::with('plan')->where('status', SubscriptionStatus::Active)->where('is_complimentary', false)->get();
        $mrr = $paying->sum(fn (Subscription $s) => (float) $s->seatPrice() * $s->seats / ($s->interval === 'year' ? 12 : 1));
        $since = now()->subDays(30);

        return response()->json([
            'mrr' => round($mrr, 2),
            'currency' => config('payments.currency'),
            'workspaces' => Workspace::count(),
            'users' => User::count(),
            'subscriptions' => Subscription::selectRaw('status, count(*) as total, sum(seats) as seats')->groupBy('status')->get(),
            'paid_seats' => (int) $paying->sum('seats'),
            'revenue_30d' => (float) Payment::where('status', PaymentStatus::Paid)->where('paid_at', '>=', $since)->sum('amount'),
            'failed_payments_30d' => Payment::where('status', PaymentStatus::Failed)->where('created_at', '>=', $since)->count(),
            'conversations_today' => Conversation::where('created_at', '>=', now()->startOfDay())->count(),
            'open_conversations' => Conversation::where('status', '!=', 'closed')->count(),
            'ai' => AiUsageLog::where('created_at', '>=', $since)
                ->selectRaw('sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, count(*) as calls, sum(case when success then 0 else 1 end) as errors, sum(case when platform_key then input_tokens else 0 end) as platform_tokens')
                ->first(),
            'documents' => KnowledgeDocument::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'failed_documents' => KnowledgeDocument::where('status', DocumentStatus::Failed)->count(),
            'signups' => Workspace::where('created_at', '>=', $since)
                ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, count(*) as total")
                ->groupBy('day')->orderBy('day')->get(),
        ]);
    }
}
