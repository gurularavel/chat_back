<?php

namespace App\Services\Knowledge;

final readonly class SearchHit
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $documentTitle,
        public int $page,
        public string $content,
        public float $similarity,
        /** From keyword search: $similarity is the share of query words found, not a vector score. */
        public bool $keyword = false,
    ) {}

    public function toSource(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'title' => $this->documentTitle,
            'page' => $this->page,
            'similarity' => round($this->similarity, 3),
        ];
    }
}
