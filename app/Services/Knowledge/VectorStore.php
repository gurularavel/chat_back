<?php

namespace App\Services\Knowledge;

interface VectorStore
{
    /**
     * @param  array<int, list<float>>  $vectors  chunk id => vector
     */
    public function store(array $vectors): void;

    /**
     * @param  list<float>  $vector
     * @return list<SearchHit> best first
     */
    public function search(int $workspaceId, string $embeddingProvider, string $embeddingModel, array $vector, string $query, int $limit): array;

    /**
     * Full-text search without embeddings.
     *
     * @return list<SearchHit> best first
     */
    public function keywordSearch(int $workspaceId, string $query, int $limit): array;
}
