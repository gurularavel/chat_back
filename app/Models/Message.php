<?php

namespace App\Models;

use App\Enums\SenderType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'workspace_id', 'sender_type', 'sender_id', 'body', 'sources', 'meta', 'read_at'])]
class Message extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'sender_type' => SenderType::class,
            'sources' => 'array',
            'meta' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Shape sent to the public widget (no internal meta). */
    public function toWidgetArray(): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type->value,
            'sender_name' => $this->sender_type === SenderType::Operator ? $this->sender?->name : null,
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
