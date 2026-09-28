<?php

namespace App\Services\Chat;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Picks the online operator with the fewest open chats (under their concurrency cap).
 * With a department, only its members are considered; a department without members
 * falls back to the whole team.
 */
class OperatorAssigner
{
    /** Operators without a heartbeat for this long are treated as offline. */
    public const ONLINE_TTL_SECONDS = 120;

    public function pick(int $workspaceId, ?int $departmentId = null): ?User
    {
        $members = $this->online($workspaceId, $departmentId)->get();

        if ($members->isEmpty()) {
            return null;
        }

        $load = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [ConversationStatus::PendingHuman, ConversationStatus::Human])
            ->whereIn('assigned_user_id', $members->pluck('user_id'))
            ->groupBy('assigned_user_id')
            ->select('assigned_user_id', DB::raw('count(*) as open'))
            ->pluck('open', 'assigned_user_id');

        $candidate = $members
            ->filter(fn (WorkspaceMember $m) => ($load[$m->user_id] ?? 0) < $m->max_concurrent_chats)
            ->sortBy(fn (WorkspaceMember $m) => [($load[$m->user_id] ?? 0), $m->last_seen_at?->timestamp * -1])
            ->first();

        return $candidate ? User::find($candidate->user_id) : null;
    }

    public function isOnline(int $workspaceId, int $userId): bool
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('is_online', true)
            ->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_TTL_SECONDS))
            ->exists();
    }

    public function anyOnline(int $workspaceId, ?int $departmentId = null): bool
    {
        return $this->online($workspaceId, $departmentId)->exists();
    }

    /** @return Builder<WorkspaceMember> */
    private function online(int $workspaceId, ?int $departmentId): Builder
    {
        $query = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_online', true)
            ->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_TTL_SECONDS));

        $departmentUserIds = $departmentId
            ? DB::table('department_user')->where('department_id', $departmentId)->pluck('user_id')
            : collect();

        if ($departmentUserIds->isNotEmpty()) {
            $query->whereIn('user_id', $departmentUserIds);
        }

        return $query;
    }
}
