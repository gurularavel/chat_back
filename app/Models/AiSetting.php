<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'system_prompt', 'tone', 'temperature', 'similarity_threshold', 'handoff_mode', 'fallback_message'])]
class AiSetting extends Model
{
    use BelongsToWorkspace;

    protected $attributes = [
        'tone' => 'friendly',
        'temperature' => 0.2,
        'similarity_threshold' => 0.35,
        'handoff_mode' => 'auto',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'similarity_threshold' => 'float',
            'fallback_message' => 'array',
        ];
    }

    public function fallbackFor(?string $locale): string
    {
        $messages = $this->fallback_message ?? [];

        return $messages[$locale] ?? $messages['en'] ?? __('chat.fallback', [], $locale);
    }
}
