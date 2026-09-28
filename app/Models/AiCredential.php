<?php

namespace App\Models;

use App\Enums\AiProvider;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'provider', 'label', 'api_key', 'chat_model', 'embedding_model', 'is_default', 'status', 'last_error', 'last_verified_at'])]
#[Hidden(['api_key'])]
class AiCredential extends Model
{
    use BelongsToWorkspace;

    protected $appends = ['masked_key'];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'is_default' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    public function getMaskedKeyAttribute(): string
    {
        $key = (string) $this->api_key;

        return strlen($key) <= 8 ? '••••' : substr($key, 0, 4).'…'.substr($key, -4);
    }
}
