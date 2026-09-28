<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeDocumentResource;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AuditLog;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AiCredentialResolver;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request, AiCredentialResolver $resolver): AnonymousResourceCollection
    {
        $workspace = $request->attributes->get('workspace');

        try {
            $embedding = $resolver->embeddingFor($workspace);
        } catch (AiNotConfiguredException) {
            $embedding = null;
        }

        $documents = KnowledgeDocument::latest()->get();
        $stale = $embedding ? $documents->filter(fn ($d) => $d->status === DocumentStatus::Ready
            && ($d->embedding_provider !== $embedding->provider->value || $d->embedding_model !== $embedding->model))->count() : 0;

        return KnowledgeDocumentResource::collection($documents)->additional([
            'meta' => [
                'embedding' => $embedding ? ['provider' => $embedding->provider->value, 'model' => $embedding->model, 'platform_key' => $embedding->platformKey] : null,
                'retrieval' => $resolver->retrievalMode($workspace),
                'stale_documents' => $stale,
            ],
        ]);
    }

    public function store(Request $request, PlanLimits $limits, AiCredentialResolver $resolver): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:102400'],
            'title' => ['nullable', 'string', 'max:190'],
        ]);

        // Any AI key is enough: without an embedding key documents use keyword search.
        if ($resolver->retrievalMode($workspace) === null) {
            abort(422, __('knowledge.needs_ai_key'));
        }

        $file = $request->file('file');
        $limits->ensureCanUploadDocument($workspace, $file->getSize());

        $path = $file->storeAs("workspaces/{$workspace->id}/docs", Str::uuid().'.pdf', 'local');

        $document = KnowledgeDocument::create([
            'uploaded_by' => $request->user()->id,
            'title' => $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'disk_path' => $path,
            'mime' => 'application/pdf',
            'size' => $file->getSize(),
            'status' => DocumentStatus::Uploaded,
        ]);

        ProcessKnowledgeDocument::dispatch($document->id);
        AuditLog::record('document.uploaded', $document, ['title' => $document->title]);

        return (new KnowledgeDocumentResource($document))->response()->setStatusCode(201);
    }

    public function destroy(KnowledgeDocument $document): JsonResponse
    {
        Storage::disk('local')->delete($document->disk_path);
        $document->delete();
        AuditLog::record('document.deleted', $document, ['title' => $document->title]);

        return response()->json(['ok' => true]);
    }

    public function reprocess(KnowledgeDocument $document): KnowledgeDocumentResource
    {
        $document->update(['status' => DocumentStatus::Uploaded, 'error' => null]);
        ProcessKnowledgeDocument::dispatch($document->id);

        return new KnowledgeDocumentResource($document);
    }

    /** Re-embed everything, e.g. after switching the embedding provider/model. */
    public function reindex(): JsonResponse
    {
        $count = 0;
        KnowledgeDocument::whereIn('status', [DocumentStatus::Ready, DocumentStatus::Failed])->each(function (KnowledgeDocument $document) use (&$count) {
            $document->update(['status' => DocumentStatus::Uploaded, 'error' => null]);
            ProcessKnowledgeDocument::dispatch($document->id);
            $count++;
        });

        return response()->json(['queued' => $count]);
    }
}
