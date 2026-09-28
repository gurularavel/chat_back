<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->attributes->get('workspace');

        return MemberResource::collection($workspace->members()->orderBy('name')->get());
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'role' => ['sometimes', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Operator->value])],
            'max_concurrent_chats' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $member = $workspace->members()->whereKey($userId)->firstOrFail();
        if ($member->pivot->role === WorkspaceRole::Owner && isset($data['role'])) {
            abort(422, 'The owner role cannot be changed.');
        }

        $workspace->members()->updateExistingPivot($userId, $data);
        AuditLog::record('member.updated', $member, $data);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $member = $workspace->members()->whereKey($userId)->firstOrFail();

        if ($member->pivot->role === WorkspaceRole::Owner) {
            abort(422, 'The owner cannot be removed.');
        }

        $workspace->members()->detach($userId);
        DB::table('department_user')
            ->where('user_id', $userId)
            ->whereIn('department_id', $workspace->departments()->select('id'))
            ->delete();
        if ($member->current_workspace_id === $workspace->id) {
            User::whereKey($userId)->update(['current_workspace_id' => null]);
        }
        AuditLog::record('member.removed', $member);

        return response()->json(['ok' => true]);
    }
}
