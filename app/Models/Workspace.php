<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'owner_id', 'locale', 'timezone', 'sla_first_response_minutes', 'billing_name', 'billing_email', 'billing_tax_id', 'billing_address', 'status', 'trial_ends_at'])]
class Workspace extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'sla_first_response_minutes' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMember::class)
            ->withPivot(['id', 'role', 'is_online', 'last_seen_at', 'max_concurrent_chats'])
            ->withTimestamps();
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function aiSetting(): HasOne
    {
        return $this->hasOne(AiSetting::class);
    }

    public function aiCredentials(): HasMany
    {
        return $this->hasMany(AiCredential::class);
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(Widget::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
