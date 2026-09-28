<?php

namespace App\Http\Resources;

use App\Models\Widget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Widget */
class WidgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'public_key' => $this->public_key,
            'allowed_domains' => $this->allowed_domains ?? [],
            'appearance' => $this->publicAppearance(),
            'texts' => (object) ($this->texts ?? []),
            'pre_chat_form' => $this->pre_chat_form ?? ['enabled' => false, 'fields' => []],
            'social_links' => (object) ($this->social_links ?? []),
            'show_social_links' => $this->show_social_links,
            'hide_when_offline' => $this->hide_when_offline,
            'is_active' => $this->is_active,
            'embed_code' => sprintf(
                '<script src="%s/widget/loader.js" data-key="%s" async></script>',
                rtrim(config('app.url'), '/'),
                $this->public_key,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
