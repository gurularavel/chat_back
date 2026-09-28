<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'locale' => ['sometimes', Rule::in(config('chat.locales'))],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $workspace->update($data);
        AuditLog::record('workspace.updated', $workspace, $data);

        return response()->json(['ok' => true]);
    }
}
