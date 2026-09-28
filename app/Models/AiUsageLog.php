<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'conversation_id', 'provider', 'model', 'type', 'platform_key', 'input_tokens', 'output_tokens', 'latency_ms', 'success', 'error'])]
class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['success' => 'boolean', 'platform_key' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
