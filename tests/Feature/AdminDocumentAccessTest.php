<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MinimalPdf;
use Tests\TestCase;

class AdminDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_a_customer_document_and_its_extracted_text(): void
    {
        Storage::fake('local');
        $workspace = $this->createWorkspace();
        Storage::disk('local')->put('workspaces/1/docs/cv.pdf', MinimalPdf::make(['Hello']));

        $document = KnowledgeDocument::create([
            'workspace_id' => $workspace->id,
            'title' => 'Gülnar CV',
            'disk_path' => 'workspaces/1/docs/cv.pdf',
            'status' => DocumentStatus::Ready,
        ]);
        KnowledgeChunk::create(['document_id' => $document->id, 'workspace_id' => $workspace->id, 'page' => 1, 'chunk_index' => 0, 'content' => 'UX/UI dizayn kursu']);

        // Tenants cannot use the admin endpoints, even for their own documents.
        $this->actingAs($workspace->owner)->get("/api/admin/documents/{$document->id}/file")->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        $response = $this->actingAs($admin)->get("/api/admin/documents/{$document->id}/file")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        $this->actingAs($admin)->getJson("/api/admin/documents/{$document->id}/text")
            ->assertOk()
            ->assertJsonPath('chunks.0.content', 'UX/UI dizayn kursu')
            ->assertJsonPath('document.workspace.name', $workspace->name);

        $this->assertSame(2, AuditLog::whereIn('action', ['admin.document.viewed', 'admin.document.text_viewed'])->count());
    }

    public function test_missing_file_returns_404(): void
    {
        Storage::fake('local');
        $workspace = $this->createWorkspace();
        $document = KnowledgeDocument::create(['workspace_id' => $workspace->id, 'title' => 'Gone', 'disk_path' => 'nope.pdf', 'status' => DocumentStatus::Ready]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        $this->actingAs($admin)->getJson("/api/admin/documents/{$document->id}/file")->assertNotFound();
    }
}
