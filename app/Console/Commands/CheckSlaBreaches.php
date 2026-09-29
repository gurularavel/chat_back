<?php

namespace App\Console\Commands;

use App\Enums\ConversationStatus;
use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\SlaBreachedNotification;
use App\Services\Chat\ConversationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('sla:check')]
#[Description('Flag handed-off conversations whose first-response deadline passed and alert the team')]
class CheckSlaBreaches extends Command
{
    public function handle(ConversationService $conversations): int
    {
        $breached = 0;

        Conversation::withoutGlobalScopes()
            ->with(['workspace', 'visitor', 'department'])
            ->whereIn('status', [ConversationStatus::PendingHuman, ConversationStatus::Human])
            ->where('sla_due_at', '<=', now())
            ->whereNull('first_response_at')
            ->whereNull('sla_breached_at')
            ->each(function (Conversation $conversation) use ($conversations, &$breached) {
                try {
                    $conversations->markSlaBreached($conversation);
                    $this->recipients($conversation)->each(
                        fn (User $user) => $user->notify((new SlaBreachedNotification($conversation))->locale($user->locale ?: $conversation->workspace->locale))
                    );
                    $breached++;
                } catch (Throwable $e) {
                    report($e);
                    $this->error("conversation #{$conversation->id}: {$e->getMessage()}");
                }
            });

        $this->info("Flagged {$breached} SLA breach(es).");

        return self::SUCCESS;
    }

    /**
     * Owners and admins, plus the operator the chat is assigned to.
     *
     * @return Collection<int, User>
     */
    private function recipients(Conversation $conversation): Collection
    {
        return $conversation->workspace->members()
            ->where(fn ($q) => $q
                ->whereIn('workspace_user.role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
                ->when($conversation->assigned_user_id, fn ($q, $id) => $q->orWhere('users.id', $id)))
            ->get();
    }
}
