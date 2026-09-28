<?php

namespace Tests\Feature;

use App\Models\AiCredential;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;
use Tests\Support\MinimalPdf;
use Tests\TestCase;

class KnowledgePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_pdf_is_parsed_chunked_and_embedded(): void
    {
        Storage::fake('local');
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([
                Embedding::fromArray($this->fakeVector(1)),
                Embedding::fromArray($this->fakeVector(2)),
            ]),
        ]);

        $workspace = $this->createWorkspace();
        AiCredential::create([
            'workspace_id' => $workspace->id, 'provider' => 'gemini', 'api_key' => 'gm-test-1234567890',
            'chat_model' => 'gemini-2.5-flash', 'embedding_model' => 'gemini-embedding-001', 'is_default' => true,
        ]);

        $pdf = UploadedFile::fake()->createWithContent('prices.pdf', MinimalPdf::make([
            'Standard plan costs 20 AZN per month.',
            'Support is available from 9:00 to 18:00.',
        ]));

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->post('/api/documents', ['file' => $pdf], ['Accept' => 'application/json'])
            ->assertCreated();

        $document = KnowledgeDocument::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ready', $document->status->value, (string) $document->error);
        $this->assertSame(2, $document->pages);
        $this->assertSame('gemini', $document->embedding_provider);
        $this->assertSame(2, KnowledgeChunk::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('knowledge_chunks')->whereNull('embedding')->count());
        $this->assertStringContainsString('20 AZN', KnowledgeChunk::withoutGlobalScopes()->where('page', 1)->value('content'));
    }

    public function test_upload_requires_an_ai_key(): void
    {
        Storage::fake('local');
        $workspace = $this->createWorkspace();

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->post('/api/documents', ['file' => UploadedFile::fake()->createWithContent('a.pdf', MinimalPdf::make(['x']))], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_claude_only_workspace_indexes_documents_for_keyword_search(): void
    {
        Storage::fake('local');
        $fake = Prism::fake([]);
        $workspace = $this->createWorkspace();
        AiCredential::create([
            'workspace_id' => $workspace->id, 'provider' => 'anthropic', 'api_key' => 'sk-ant-1234567890',
            'chat_model' => 'claude-haiku-4-5', 'is_default' => true,
        ]);

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->post('/api/documents', ['file' => UploadedFile::fake()->createWithContent('a.pdf', MinimalPdf::make(['Standard plan costs 20 AZN per month.']))], ['Accept' => 'application/json'])
            ->assertCreated();

        $document = KnowledgeDocument::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ready', $document->status->value, (string) $document->error);
        $this->assertSame('keyword', $document->embedding_provider);
        $this->assertSame(1, $document->chunks_count);
        $this->assertSame(1, DB::table('knowledge_chunks')->whereNull('embedding')->count());
        $fake->assertCallCount(0); // no embedding API was called

        $this->actingInWorkspace($workspace->owner, $workspace)->getJson('/api/documents')
            ->assertJsonPath('meta.retrieval', 'keyword')
            ->assertJsonPath('meta.embedding', null)
            ->assertJsonPath('meta.stale_documents', 0);
    }

    public function test_platform_embedding_key_gives_claude_only_workspaces_semantic_search(): void
    {
        Storage::fake('local');
        $workspace = $this->createWorkspace();
        AiCredential::create([
            'workspace_id' => $workspace->id, 'provider' => 'anthropic', 'api_key' => 'sk-ant-1234567890',
            'chat_model' => 'claude-haiku-4-5', 'is_default' => true,
        ]);

        config(['chat.platform_embedding.api_key' => 'sk-platform-123456']);
        Prism::fake([EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray($this->fakeVector(3))])]);

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->post('/api/documents', ['file' => UploadedFile::fake()->createWithContent('a.pdf', MinimalPdf::make(['Hello world text']))], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertTrue(DB::table('ai_usage_logs')->where('platform_key', true)->exists());
    }
}
