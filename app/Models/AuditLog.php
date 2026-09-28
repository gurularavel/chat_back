<?php

namespace App\Models;

use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['actor_id', 'workspace_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public static function record(string $action, ?Model $subject = null, array $meta = [], ?int $workspaceId = null): self
    {
        return static::create([
            'actor_id' => auth()->id(),
            'workspace_id' => $workspaceId ?? app(CurrentWorkspace::class)->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
