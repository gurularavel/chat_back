<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group of operators; visitors pick one when starting a chat and handoffs route to its members. */
#[Fillable(['workspace_id', 'name', 'sort', 'is_active'])]
class Department extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
