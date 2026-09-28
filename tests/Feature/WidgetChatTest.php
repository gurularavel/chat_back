<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
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
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class WidgetChatTest extends TestCase
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
            'provider' => 'openai',
            'api_key' => 'sk-test-1234567890',
            'chat_model' => 'gpt-4.1-mini',
            'embedding_model' => 'text-embedding-3-small',
            'is_default' => true,
            'status' => 'valid',
        ]);

        // One ready document with a chunk pointing in direction 7.
        $document = KnowledgeDocument::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Delivery policy',
            'disk_path' => 'x.pdf',
            'status' => DocumentStatus::Ready,
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
        ]);
        $chunk = KnowledgeChunk::create([
            'document_id' => $document->id,
            'workspace_id' => $this->workspace->id,
            'page' => 2,
            'chunk_index' => 0,
            'content' => 'Delivery in Baku is free and takes 1-2 days.',
        ]);
        (new PgVectorStore)->store([$chunk->id => $this->fakeVector(7)]);
    }

    public function test_visitor_question_is_answered_from_the_pdf_knowledge(): void
    {
        $fake = Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray($this->fakeVector(7))]),
            TextResponseFake::make()->withText('Delivery in Baku is free and takes 1-2 days.')->withUsage(new Usage(120, 14)),
        ]);

        $token = $this->startWidgetSession();

        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'How long does delivery take in Baku?'])
            ->assertCreated();

        $ai = Message::withoutGlobalScopes()->where('sender_type', 'ai')->firstOrFail();
        $this->assertSame('Delivery in Baku is free and takes 1-2 days.', $ai->body);
        $this->assertSame('Delivery policy', $ai->sources[0]['title']);
        $this->assertSame(2, $ai->sources[0]['page']);

        $fake->assertRequest(function (array $requests) {
            $this->assertStringContainsString('Delivery in Baku is free', $requests[1]->systemPrompts()[0]->content);
        });

        // Visitor sees the AI answer
        $this->withHeader('X-Visitor-Token', $token)
            ->getJson("/api/widget/{$this->widget->public_key}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.sender_type', 'ai')
            ->assertJsonMissingPath('messages.1.sources');
    }

    public function test_unknown_answer_hands_off_to_an_online_operator(): void
    {
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray($this->fakeVector(99))]),
            TextResponseFake::make()->withText('[[HANDOFF]]'),
        ]);
        $operator = $this->addMember($this->workspace, WorkspaceRole::Operator);
        $this->workspace->members()->updateExistingPivot($operator->id, ['is_online' => true, 'last_seen_at' => now()]);

        $token = $this->startWidgetSession();
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Do you sell gift cards?'])
            ->assertCreated();

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ConversationStatus::PendingHuman, $conversation->status);
        $this->assertSame($operator->id, $conversation->assigned_user_id);
        $this->assertTrue($conversation->was_handed_off);

        // Operator replies â†’ conversation becomes human, the AI stays silent afterwards.
        $this->actingInWorkspace($operator, $this->workspace)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Yes, we do!'])
            ->assertCreated();
        $this->assertSame(ConversationStatus::Human, $conversation->fresh()->status);

        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Great, thanks'])
            ->assertCreated();
        $this->assertSame(0, Message::withoutGlobalScopes()->where('sender_type', 'ai')->count());
    }

    public function test_ai_takes_over_again_when_the_assigned_operator_goes_offline(): void
    {
        $operator = $this->addMember($this->workspace, WorkspaceRole::Operator);
        $this->workspace->members()->updateExistingPivot($operator->id, ['is_online' => true, 'last_seen_at' => now()]);

        $token = $this->startWidgetSession();
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray($this->fakeVector(99))]),
            TextResponseFake::make()->withText('[[HANDOFF]]'),
        ]);
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Do you sell gift cards?']);
        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        $this->actingInWorkspace($operator, $this->workspace)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Let me check'])
            ->assertCreated();
        $this->assertSame(ConversationStatus::Human, $conversation->fresh()->status);

        // Operator closes the panel: heartbeats stop.
        $this->workspace->members()->updateExistingPivot($operator->id, ['last_seen_at' => now()->subMinutes(5)]);

        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray($this->fakeVector(7))]),
            TextResponseFake::make()->withText('Delivery in Baku is free and takes 1-2 days.'),
        ]);
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'How long does delivery take?'])
            ->assertCreated();

        $this->assertSame(ConversationStatus::Ai, $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->assigned_user_id);
        $this->assertSame('Delivery in Baku is free and takes 1-2 days.', Message::withoutGlobalScopes()->where('sender_type', 'ai')->latest('id')->value('body'));
    }

    public function test_asking_for_a_human_skips_the_ai(): void
    {
        $fake = Prism::fake([]);
        $token = $this->startWidgetSession();

        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Operatorla danÄ±ÅŸmaq istÉ™yirÉ™m'])
            ->assertCreated();

        $this->assertSame(ConversationStatus::PendingHuman, Conversation::withoutGlobalScopes()->first()->status);
        $fake->assertCallCount(0);
        // No operator online â†’ offline notice for the visitor
        $this->assertTrue(Message::withoutGlobalScopes()->where('sender_type', 'system')->exists());
    }

    public function test_frame_without_whitelist_sends_no_frame_ancestors_policy(): void
    {
        // "frame-ancestors *" would block file:// pages, so no header at all.
        $this->get("/widget/frame/{$this->widget->public_key}")
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_domain_whitelist_and_invalid_tokens_are_rejected(): void
    {
        $this->widget->update(['allowed_domains' => ['shop.az', '*.shop.az']]);

        $this->postJson("/api/widget/{$this->widget->public_key}/session", ['host' => 'evil.com'])->assertForbidden();
        $this->postJson("/api/widget/{$this->widget->public_key}/session", ['host' => 'm.shop.az'])->assertOk();

        $this->get("/widget/frame/{$this->widget->public_key}")
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://shop.az http://shop.az https://*.shop.az http://*.shop.az');

        $this->withHeader('X-Visitor-Token', str_repeat('a', 48))
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'hi'])
            ->assertUnauthorized();
    }

    public function test_expired_trial_disables_the_widget(): void
    {
        $this->workspace->subscription()->update(['current_period_end' => now()->subDay()]);

        $this->getJson("/api/widget/{$this->widget->public_key}/config")->assertOk()->assertJsonPath('enabled', false);

        $token = $this->startWidgetSession();
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'hello'])
            ->assertStatus(503);
    }

    private function startWidgetSession(): string
    {
        return $this->postJson("/api/widget/{$this->widget->public_key}/session", [
            'host' => 'example.com',
            'url' => 'https://example.com/pricing',
            'locale' => 'en-US',
        ])->assertOk()->json('visitor_token');
    }
}
