<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Widget: status changes (operator joined, chat closed…). */
class VisitorConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('visitor.'.$this->conversation->visitor_id)];
    }

    public function broadcastAs(): string
    {
        return 'conversation';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'status' => $this->conversation->status->value,
            'operator_name' => $this->conversation->assignee?->name,
        ];
    }
}
