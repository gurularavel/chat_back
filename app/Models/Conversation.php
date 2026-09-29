<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['workspace_id', 'widget_id', 'visitor_id', 'department_id', 'assigned_user_id', 'status', 'channel', 'locale', 'was_handed_off', 'handoff_at', 'first_response_at', 'sla_due_at', 'sla_breached_at', 'rating', 'last_message_at', 'closed_at'])]
class Conversation extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'was_handed_off' => 'boolean',
            'handoff_at' => 'datetime',
            'first_response_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->status !== ConversationStatus::Closed;
    }

    /** A handed-off visitor is still waiting for the first operator reply. */
    public function awaitsFirstResponse(): bool
    {
        return $this->handoff_at !== null && $this->first_response_at === null;
    }
}
