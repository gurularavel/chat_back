<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Services\Workspaces\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request, PlanLimits $limits): JsonResponse
    {
        $user = $request->user();
        $workspaces = $user->workspaces()->orderBy('name')->get();

        $current = $workspaces->firstWhere('id', $user->current_workspace_id) ?? $workspaces->first();
        if ($current && $current->id !== $user->current_workspace_id) {
            $user->forceFill(['current_workspace_id' => $current->id])->save();
        }

        $subscription = $current ? $limits->subscription($current)?->load(['plan', 'paymentMethod']) : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'is_superadmin' => $user->is_superadmin,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
            'workspaces' => $workspaces->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'role' => $w->pivot->role->value,
            ]),
            'current_workspace' => $current ? [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
                'locale' => $current->locale,
                'timezone' => $current->timezone,
                'status' => $current->status,
                'role' => $current->pivot->role->value,
                'service_active' => $limits->isServiceActive($current),
                'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
                // Unpaid invoice for the "payment due" banner (only billing managers see it).
                'open_invoice' => $current->pivot->role !== WorkspaceRole::Operator ? $this->openInvoice($current) : null,
            ] : null,
        ]);
    }

    private function openInvoice(Workspace $workspace): ?array
    {
        $invoice = Invoice::withoutGlobalScopes()->open()->where('workspace_id', $workspace->id)->oldest('due_at')->first();

        return $invoice ? $invoice->only(['id', 'number', 'total', 'currency']) + [
            'due_at' => $invoice->due_at?->toIso8601String(),
            'overdue' => $invoice->isOverdue(),
        ] : null;
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'locale' => ['sometimes', Rule::in(config('chat.locales'))],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['sometimes', 'confirmed', 'min:8'],
        ]);

        $request->user()->fill(collect($data)->only(['name', 'locale', 'password'])->all())->save();

        return response()->json(['ok' => true]);
    }

    public function switchWorkspace(Request $request): JsonResponse
    {
        $data = $request->validate(['workspace_id' => ['required', 'integer']]);
        $user = $request->user();

        if (! $user->roleIn($data['workspace_id'])) {
            abort(403);
        }

        $user->forceFill(['current_workspace_id' => $data['workspace_id']])->save();

        return response()->json(['ok' => true]);
    }

    public function createWorkspace(Request $request, WorkspaceService $workspaces): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $workspace = $workspaces->create($request->user(), $data['name']);

        return response()->json(['id' => $workspace->id], 201);
    }
}
