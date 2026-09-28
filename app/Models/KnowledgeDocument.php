<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'uploaded_by', 'title', 'disk_path', 'mime', 'size', 'pages', 'chunks_count', 'status', 'error', 'embedding_provider', 'embedding_model'])]
#[Hidden(['disk_path'])]
class KnowledgeDocument extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }
}
