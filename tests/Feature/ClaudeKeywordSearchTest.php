<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\DocumentStatus;
use App\Models\AiCredential;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\Widget;
use App\Models\Workspace;
use App\Services\Knowledge\PgVectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class ClaudeKeywordSearchTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Widget $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->createWorkspace();
        $this->widget = Widget::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->first();

        AiCredential::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test-1234567890',
            'chat_model' => 'claude-haiku-4-5',
            'is_default' => true,
            'status' => 'valid',
        ]);

        $document = KnowledgeDocument::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Çatdırılma qaydaları',
            'disk_path' => 'x.pdf',
            'status' => DocumentStatus::Ready,
            'embedding_provider' => 'keyword',
        ]);
        foreach ([
            'Bakı daxilində çatdırılma pulsuzdur və 1-2 gün çəkir.',
            'Mağazamız həftə içi 09:00-18:00 arası işləyir.',
        ] as $i => $content) {
            KnowledgeChunk::create([
                'document_id' => $document->id,
                'workspace_id' => $this->workspace->id,
                'page' => $i + 1,
                'chunk_index' => $i,
                'content' => $content,
            ]);
        }
    }

    public function test_stemming_matches_inflected_words(): void
    {
        // Azerbaijani letters (ı/İ, ə, ç, …) are folded to Latin on both sides, so casing and
        // text typed without them ("catdirilma", "qiymeti") still match.
        $this->assertSame(['catdirilm', 'qiyme'], PgVectorStore::stems('ÇATDIRILMASI qiyməti'));
        $this->assertSame(PgVectorStore::stems('ÇATDIRILMASI qiyməti'), PgVectorStore::stems('catdirilmasi qiymeti'));
        $this->assertNotEmpty(app(PgVectorStore::class)->keywordSearch($this->workspace->id, 'catdirilma nece gun cekir', 5));

        $hits = app(PgVectorStore::class)->keywordSearch($this->workspace->id, 'Çatdırılması neçə gün çəkir?', 5);

        $this->assertNotEmpty($hits);
        $this->assertSame(1, $hits[0]->page);
        $this->assertGreaterThanOrEqual(0.5, $hits[0]->similarity);
    }

    public function test_claude_answers_visitor_from_keyword_search_without_embeddings(): void
    {
        $fake = Prism::fake([
            TextResponseFake::make()->withText('Bakı daxilində çatdırılma pulsuzdur, 1-2 gün çəkir.'),
        ]);

        $token = $this->postJson("/api/widget/{$this->widget->public_key}/session", ['host' => 'shop.az', 'locale' => 'az'])
            ->json('visitor_token');

        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Bakıda çatdırılma neçə gün çəkir?'])
            ->assertCreated();

        $answer = Message::withoutGlobalScopes()->where('sender_type', 'ai')->firstOrFail();
        $this->assertSame('Bakı daxilində çatdırılma pulsuzdur, 1-2 gün çəkir.', $answer->body);
        $this->assertSame('Çatdırılma qaydaları', $answer->sources[0]['title']);
        $this->assertSame('anthropic', $answer->meta['provider']);
        $this->assertSame(ConversationStatus::Ai, Conversation::withoutGlobalScopes()->first()->status);

        // Only the chat call — no embeddings request — and the passage was in the prompt.
        $fake->assertCallCount(1);
        $fake->assertRequest(function (array $requests) {
            $this->assertStringContainsString('çatdırılma pulsuzdur', $requests[0]->systemPrompts()[0]->content);
        });
    }
}
