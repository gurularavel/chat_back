<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A workspace member (User loaded through the workspace_user pivot). @mixin \App\Models\User */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->pivot?->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role instanceof \BackedEnum ? $role->value : $role,
            'is_online' => (bool) $this->pivot?->is_online,
            'last_seen_at' => $this->pivot?->last_seen_at?->toIso8601String(),
            'max_concurrent_chats' => $this->pivot?->max_concurrent_chats,
        ];
    }
}
