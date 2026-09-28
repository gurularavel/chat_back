<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['workspace_id' => ['nullable', 'integer'], 'action' => ['nullable', 'string', 'max:100']]);

        return response()->json(
            AuditLog::with(['actor:id,name,email', 'workspace:id,name'])
                ->when($request->input('workspace_id'), fn ($q, $id) => $q->where('workspace_id', $id))
                ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->latest()
                ->paginate(50)
        );
    }
}
