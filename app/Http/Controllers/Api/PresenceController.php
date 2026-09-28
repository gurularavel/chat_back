<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Operator online status; the inbox sends a heartbeat every ~30s while open. */
class PresenceController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['online' => ['required', 'boolean']]);
        $workspace = $request->attributes->get('workspace');

        $workspace->members()->updateExistingPivot($request->user()->id, [
            'is_online' => $data['online'],
            'last_seen_at' => now(),
        ]);

        return response()->json(['online' => $data['online']]);
    }
}
