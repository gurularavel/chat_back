<?php

namespace App\Http\Controllers\Api;

use App\Enums\SenderType;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PlanLimits $limits): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $workspace = $request->attributes->get('workspace');
        $since = now()->subDays((int) $request->input('days', 30))->startOfDay();

        $conversations = Conversation::where('created_at', '>=', $since);
        $total = (clone $conversations)->count();
        $handedOff = (clone $conversations)->where('was_handed_off', true)->count();

        $daily = Conversation::where('created_at', '>=', $since)
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, count(*) as total, sum(case when was_handed_off then 1 else 0 end) as handed_off")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $usage = AiUsageLog::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('type, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, count(*) as calls, sum(case when success then 0 else 1 end) as errors, avg(latency_ms) as avg_latency')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return response()->json([
            'conversations' => $total,
            'handed_off' => $handedOff,
            'ai_resolved_rate' => $total ? round(($total - $handedOff) / $total * 100, 1) : null,
            'ai_messages' => Message::where('created_at', '>=', $since)->where('sender_type', SenderType::Ai)->count(),
            'operator_messages' => Message::where('created_at', '>=', $since)->where('sender_type', SenderType::Operator)->count(),
            'avg_rating' => round((float) Conversation::where('created_at', '>=', $since)->whereNotNull('rating')->avg('rating'), 2) ?: null,
            'daily' => $daily,
            'ai_usage' => $usage,
            'top_pages' => Conversation::where('conversations.created_at', '>=', $since)
                ->join('visitors', 'visitors.id', '=', 'conversations.visitor_id')
                ->whereNotNull('visitors.current_url')
                ->select('visitors.current_url', DB::raw('count(*) as total'))
                ->groupBy('visitors.current_url')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),
            'limits' => $limits->usage($workspace),
        ]);
    }
}
