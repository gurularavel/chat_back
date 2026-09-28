<?php

namespace App\Events;

use App\Http\Resources\KnowledgeDocumentResource;
use App\Models\KnowledgeDocument;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public KnowledgeDocument $document) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->document->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'document.updated';
    }

    public function broadcastWith(): array
    {
        return ['document' => (new KnowledgeDocumentResource($this->document))->resolve()];
    }
}
