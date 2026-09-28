<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AiTyping implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $visitorId, public bool $typing) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('visitor.'.$this->visitorId)];
    }

    public function broadcastAs(): string
    {
        return 'typing';
    }

    public function broadcastWith(): array
    {
        return ['typing' => $this->typing, 'by' => 'ai'];
    }
}
