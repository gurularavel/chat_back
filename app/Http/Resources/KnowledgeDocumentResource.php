<?php

namespace App\Http\Resources;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KnowledgeDocument */
class KnowledgeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'size' => $this->size,
            'pages' => $this->pages,
            'chunks_count' => $this->chunks_count,
            'status' => $this->status->value,
            'error' => $this->error,
            'embedding_provider' => $this->embedding_provider,
            'embedding_model' => $this->embedding_model,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
