<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function update(Request $request, PlanLimits $limits): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'locale' => ['sometimes', Rule::in(config('chat.locales'))],
            'timezone' => ['sometimes', 'timezone:all'],
            'sla_first_response_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        if (! empty($data['sla_first_response_minutes']) && ! $limits->hasFeature($workspace, 'sla')) {
            throw ValidationException::withMessages(['sla_first_response_minutes' => __('billing.sla_not_in_plan')]);
        }

        $workspace->update($data);
        AuditLog::record('workspace.updated', $workspace, $data);

        return response()->json(['ok' => true]);
    }
}
