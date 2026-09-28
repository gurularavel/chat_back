<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365'], 'workspace_id' => ['nullable', 'integer']]);
        $since = now()->subDays((int) $request->input('days', 30));

        // Columns are table-qualified because by_workspace joins workspaces (which also has created_at).
        $base = fn () => AiUsageLog::where('ai_usage_logs.created_at', '>=', $since)
            ->when($request->input('workspace_id'), fn ($q, $id) => $q->where('ai_usage_logs.workspace_id', $id));

        $aggregate = 'count(*) as calls, sum(ai_usage_logs.input_tokens) as input_tokens, sum(ai_usage_logs.output_tokens) as output_tokens, '
            .'avg(ai_usage_logs.latency_ms)::int as avg_latency, sum(case when ai_usage_logs.success then 0 else 1 end) as errors';

        return response()->json([
            'by_model' => $base()->selectRaw("provider, model, type, {$aggregate}")->groupBy('provider', 'model', 'type')->orderByDesc('calls')->get(),
            'by_workspace' => $base()->join('workspaces', 'workspaces.id', '=', 'ai_usage_logs.workspace_id')
                ->selectRaw("workspaces.id, workspaces.name, {$aggregate}")->groupBy('workspaces.id', 'workspaces.name')->orderByDesc('calls')->limit(50)->get(),
            'daily' => $base()->selectRaw("to_char(ai_usage_logs.created_at, 'YYYY-MM-DD') as day, {$aggregate}")->groupBy('day')->orderBy('day')->get(),
            'recent_errors' => $base()->where('success', false)->with('workspace:id,name')->latest('created_at')->limit(50)->get(),
        ]);
    }
}
