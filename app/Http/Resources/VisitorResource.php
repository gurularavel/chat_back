<?php

namespace App\Http\Resources;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Visitor */
class VisitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'country' => $this->country,
            'current_url' => $this->current_url,
            'locale' => $this->locale,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
