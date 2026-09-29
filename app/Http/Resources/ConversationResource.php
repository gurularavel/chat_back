<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'widget_id' => $this->widget_id,
            'status' => $this->status->value,
            'was_handed_off' => $this->was_handed_off,
            'handoff_at' => $this->handoff_at?->toIso8601String(),
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'sla_due_at' => $this->sla_due_at?->toIso8601String(),
            'sla_breached_at' => $this->sla_breached_at?->toIso8601String(),
            'locale' => $this->locale,
            'rating' => $this->rating,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'assigned_user_id' => $this->assigned_user_id,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'visitor' => new VisitorResource($this->whenLoaded('visitor')),
            'latest_message' => new MessageResource($this->whenLoaded('latestMessage')),
            'workspace' => $this->whenLoaded('workspace', fn () => ['id' => $this->workspace->id, 'name' => $this->workspace->name]),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
