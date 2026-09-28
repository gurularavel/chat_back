<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Events\DocumentStatusChanged;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AiCredentialResolver;
use App\Services\Ai\AiGateway;
use App\Services\Knowledge\PdfTextExtractor;
use App\Services\Knowledge\TextChunker;
use App\Services\Knowledge\VectorStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * PDF → text → chunks → embeddings. Also used to re-index after the
 * workspace switches embedding model.
 */
class ProcessKnowledgeDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public int $documentId)
    {
        $this->onQueue('documents');
    }

    public function handle(PdfTextExtractor $extractor, AiGateway $ai, AiCredentialResolver $resolver, VectorStore $store): void
    {
        $document = KnowledgeDocument::withoutGlobalScopes()->with('workspace')->find($this->documentId);
        if (! $document) {
            return;
        }

        $this->status($document, DocumentStatus::Parsing);

        $pages = $extractor->extract(Storage::disk('local')->path($document->disk_path));
        $chunks = TextChunker::fromConfig()->chunk($pages);

        DB::transaction(function () use ($document, $chunks, $pages) {
            KnowledgeChunk::withoutGlobalScopes()->where('document_id', $document->id)->delete();
            foreach ($chunks as $i => $chunk) {
                KnowledgeChunk::create([
                    'document_id' => $document->id,
                    'workspace_id' => $document->workspace_id,
                    'page' => $chunk['page'],
                    'chunk_index' => $i,
                    'content' => $chunk['content'],
                    'tokens' => $chunk['tokens'],
                ]);
            }
            $document->forceFill(['pages' => max(array_column($pages, 'page')), 'chunks_count' => count($chunks)])->save();
        });

        // No embedding-capable key (e.g. Claude only): chunks are searchable through
        // their full-text column, so the document is ready without vectors.
        if (! $resolver->hasEmbeddings($document->workspace)) {
            $document->forceFill([
                'embedding_provider' => AiCredentialResolver::KEYWORD,
                'embedding_model' => null,
                'error' => null,
            ])->save();
            $this->status($document, DocumentStatus::Ready);

            return;
        }

        $embedding = $resolver->embeddingFor($document->workspace);
        $this->status($document, DocumentStatus::Embedding);

        KnowledgeChunk::withoutGlobalScopes()
            ->where('document_id', $document->id)
            ->orderBy('id')
            ->chunk(config('chat.rag.embed_batch'), function ($batch) use ($ai, $embedding, $store, $document) {
                $vectors = $ai->embed($document->workspace, $batch->pluck('content')->all(), $embedding);
                $store->store(array_combine($batch->pluck('id')->all(), $vectors));
            });

        $document->forceFill([
            'embedding_provider' => $embedding->provider->value,
            'embedding_model' => $embedding->model,
            'error' => null,
        ])->save();

        $this->status($document, DocumentStatus::Ready);
    }

    public function failed(Throwable $e): void
    {
        $document = KnowledgeDocument::withoutGlobalScopes()->find($this->documentId);
        if ($document) {
            $document->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            $this->status($document, DocumentStatus::Failed);
        }
    }

    private function status(KnowledgeDocument $document, DocumentStatus $status): void
    {
        $document->forceFill(['status' => $status])->save();

        try {
            broadcast(new DocumentStatusChanged($document));
        } catch (Throwable) {
        }
    }
}
