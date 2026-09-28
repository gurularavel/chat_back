<?php

namespace App\Services\Knowledge;

use App\Models\Workspace;
use App\Services\Ai\AiCredentialResolver;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiNotConfiguredException;
use Illuminate\Support\Facades\Log;
use Throwable;

class KnowledgeSearch
{
    public function __construct(
        private AiGateway $ai,
        private AiCredentialResolver $resolver,
        private VectorStore $store,
    ) {}

    /**
     * Semantic (hybrid) search when an embedding key exists, otherwise full-text
     * keyword search — so Claude-only workspaces still answer from their PDFs.
     *
     * @return list<SearchHit>
     */
    public function search(Workspace $workspace, string $query, ?int $limit = null, ?int $conversationId = null): array
    {
        $limit ??= config('chat.rag.top_k');

        try {
            $embedding = $this->resolver->embeddingFor($workspace);
        } catch (AiNotConfiguredException) {
            return $this->store->keywordSearch($workspace->id, $query, $limit);
        }

        try {
            [$vector] = $this->ai->embed($workspace, [$query], $embedding, $conversationId);
        } catch (Throwable $e) {
            // A broken embedding key or provider outage must not leave the visitor without an answer.
            Log::warning('Embedding failed, falling back to keyword search', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);

            return $this->store->keywordSearch($workspace->id, $query, $limit);
        }

        return $this->store->search(
            $workspace->id,
            $embedding->provider->value,
            $embedding->model,
            $vector,
            $query,
            $limit,
        );
    }
}
