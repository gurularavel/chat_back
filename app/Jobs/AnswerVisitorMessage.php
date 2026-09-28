<?php

namespace App\Jobs;

use App\Enums\ConversationStatus;
use App\Events\AiTyping;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\AiResponder;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnswerVisitorMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $conversationId, public int $messageId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiResponder $responder, CurrentWorkspace $current): void
    {
        $conversation = Conversation::withoutGlobalScopes()->with('workspace')->find($this->conversationId);
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        if (! $conversation || ! $message || $conversation->status !== ConversationStatus::Ai) {
            return;
        }

        // A newer visitor message will get its own answer; skip stale ones.
        $newer = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $message->id)
            ->where('sender_type', 'visitor')
            ->exists();
        if ($newer) {
            return;
        }

        $current->runAs($conversation->workspace, function () use ($responder, $conversation, $message) {
            $this->typing($conversation->visitor_id, true);
            try {
                $responder->respond($conversation, $message);
            } finally {
                $this->typing($conversation->visitor_id, false);
            }
        });
    }

    private function typing(int $visitorId, bool $typing): void
    {
        try {
            broadcast(new AiTyping($visitorId, $typing));
        } catch (Throwable) {
            // Realtime server down must not block answering.
        }
    }
}
