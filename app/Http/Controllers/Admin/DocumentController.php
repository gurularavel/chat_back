<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\AuditLog;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Document processing queue across tenants. */
class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'workspace_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            KnowledgeDocument::with('workspace:id,name')
                ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->input('workspace_id'), fn ($q, $id) => $q->where('workspace_id', $id))
                ->latest('updated_at')
                ->paginate(50)
        );
    }

    public function retry(KnowledgeDocument $document): JsonResponse
    {
        $document->update(['status' => DocumentStatus::Uploaded, 'error' => null]);
        ProcessKnowledgeDocument::dispatch($document->id);

        return response()->json(['ok' => true]);
    }

    /** The customer's original PDF, shown inline. Every view is audited (customer data). */
    public function file(KnowledgeDocument $document): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->disk_path), 404, 'The file is no longer stored.');

        AuditLog::record('admin.document.viewed', $document, ['title' => $document->title], $document->workspace_id);

        return $disk->response(
            $document->disk_path,
            Str::slug($document->title ?: 'document').'.pdf',
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    /** Text extracted from the PDF (what the AI actually searches), per chunk. */
    public function text(KnowledgeDocument $document): JsonResponse
    {
        AuditLog::record('admin.document.text_viewed', $document, ['title' => $document->title], $document->workspace_id);

        return response()->json([
            'document' => $document->load('workspace:id,name'),
            'chunks' => KnowledgeChunk::where('document_id', $document->id)
                ->orderBy('chunk_index')
                ->get(['id', 'page', 'chunk_index', 'content', 'tokens']),
        ]);
    }
}
