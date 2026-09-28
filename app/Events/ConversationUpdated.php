<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Operator inbox: conversation created / status / assignment changed. */
class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workspace.'.$this->conversation->workspace_id),
            new PrivateChannel('admin'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        $conversation = $this->conversation->loadMissing(['visitor', 'assignee', 'department', 'latestMessage']);

        return ['conversation' => (new ConversationResource($conversation))->resolve()];
    }
}
