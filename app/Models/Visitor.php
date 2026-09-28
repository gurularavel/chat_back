<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'widget_id', 'token', 'name', 'email', 'phone', 'ip', 'user_agent', 'country', 'current_url', 'locale', 'meta', 'last_seen_at'])]
#[Hidden(['token'])]
class Visitor extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['meta' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
