<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MemberResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\AiCredential;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
        ]);

        $workspaces = Workspace::query()
            ->with(['owner:id,name,email', 'subscription.plan'])
            ->withCount(['members', 'conversations', 'documents'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$s}%")
                ->orWhereHas('owner', fn ($o) => $o->where('email', 'ilike', "%{$s}%"))))
            ->when($request->input('status'), fn ($q, $s) => in_array($s, ['active', 'suspended'], true)
                ? $q->where('status', $s)
                : $q->whereHas('subscription', fn ($sq) => $sq->where('status', $s)))
            ->latest()
            ->paginate(25);

        return response()->json($workspaces->through(fn (Workspace $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'status' => $w->status,
            'owner' => $w->owner,
            'members_count' => $w->members_count,
            'conversations_count' => $w->conversations_count,
            'documents_count' => $w->documents_count,
            'subscription' => $w->subscription ? [
                'status' => $w->subscription->status->value,
                'plan' => $w->subscription->plan->code,
                'seats' => $w->subscription->seats,
                'current_period_end' => $w->subscription->current_period_end?->toIso8601String(),
            ] : null,
            'created_at' => $w->created_at->toIso8601String(),
        ]));
    }

    public function show(Workspace $workspace, PlanLimits $limits): JsonResponse
    {
        return response()->json([
            'workspace' => $workspace->load('owner:id,name,email'),
            'members' => MemberResource::collection($workspace->members()->get()),
            'subscription' => ($s = $limits->subscription($workspace)) ? new SubscriptionResource($s->load(['plan', 'paymentMethod'])) : null,
            'usage' => $limits->usage($workspace),
            'ai_credentials' => AiCredential::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(['id', 'provider', 'chat_model', 'embedding_model', 'status', 'is_default', 'last_error', 'last_verified_at']),
            'recent_conversations' => ConversationResource::collection(
                Conversation::where('workspace_id', $workspace->id)->with(['visitor', 'assignee', 'latestMessage'])->latest('last_message_at')->limit(20)->get()
            ),
            'audit' => AuditLog::where('workspace_id', $workspace->id)->with('actor:id,name')->latest()->limit(30)->get(),
        ]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        $workspace->update($data);
        AuditLog::record('admin.workspace.'.$data['status'], $workspace, [], $workspace->id);

        return response()->json(['ok' => true]);
    }

    /** Manual subscription override (support cases, extending trials, comp seats). */
    public function updateSubscription(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['sometimes', 'exists:plans,id'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
            'current_period_end' => ['sometimes', 'date'],
            'extend_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();

        if (isset($data['extend_days'])) {
            $base = $subscription->current_period_end && $subscription->current_period_end->isFuture() ? $subscription->current_period_end : now();
            $data['current_period_end'] = $base->copy()->addDays($data['extend_days']);
            unset($data['extend_days']);
        }

        $subscription->update($data);
        AuditLog::record('admin.subscription.updated', $subscription, $request->all(), $workspace->id);

        return response()->json(['subscription' => new SubscriptionResource($subscription->fresh(['plan', 'paymentMethod']))]);
    }

    /** Superadmin opens the tenant panel (frontend then sends X-Workspace). */
    public function impersonate(Workspace $workspace): JsonResponse
    {
        AuditLog::record('admin.impersonate', $workspace, [], $workspace->id);

        return response()->json(['workspace_id' => $workspace->id]);
    }
}
