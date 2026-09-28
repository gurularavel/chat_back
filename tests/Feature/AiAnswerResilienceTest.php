<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Events\AiTyping;
use App\Events\VisitorMessageCreated;
use App\Models\AiCredential;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Widget;
use App\Services\Ai\AiCredentialResolver;
use App\Services\Ai\AiGateway;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use RuntimeException;
use Tests\TestCase;

class AiAnswerResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_broken_embedding_key_does_not_stop_the_ai_from_answering(): void
    {
        $workspace = $this->createWorkspace();
        $widget = Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        AiCredential::create([
            'workspace_id' => $workspace->id,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test-1234567890',
            'chat_model' => 'claude-sonnet-5',
            'is_default' => true,
            'status' => 'valid',
        ]);
        // Claude has no embeddings, so the platform OpenAI key is used for search — and it is wrong.
        PlatformSetting::put('platform_embedding_provider', 'openai');
        PlatformSetting::put('platform_embedding_key', 'sk-wrong-key-000000000000');
        $gateway = Mockery::mock(AiGateway::class, [app(AiCredentialResolver::class)])->makePartial();
        $gateway->shouldReceive('embed')->andThrow(new RuntimeException('OpenAI Error [401]: Incorrect API key provided'));
        $this->instance(AiGateway::class, $gateway);
        Prism::fake([TextResponseFake::make()->withText('Salam! Sizə necə kömək edə bilərəm?')]);

        $token = $this->postJson("/api/widget/{$widget->public_key}/session", ['locale' => 'az'])->json('visitor_token');
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$widget->public_key}/messages", ['body' => 'salam'])
            ->assertCreated();

        $this->assertSame('Salam! Sizə necə kömək edə bilərəm?', Message::withoutGlobalScopes()->where('sender_type', 'ai')->value('body'));
        $this->assertFalse(Message::withoutGlobalScopes()->where('sender_type', 'system')->exists()); // not handed off
    }

    public function test_the_answer_reaches_the_widget_before_the_typing_indicator_stops(): void
    {
        $workspace = $this->createWorkspace();
        $widget = Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        AiCredential::create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'api_key' => 'sk-ant-test-1234567890', 'chat_model' => 'claude-sonnet-5', 'is_default' => true, 'status' => 'valid']);
        Prism::fake([TextResponseFake::make()->withText('Salam!')]);
        $order = [];
        Event::listen([VisitorMessageCreated::class, AiTyping::class], function (object $event) use (&$order) {
            $order[] = $event instanceof AiTyping ? 'typing:'.json_encode($event->typing) : $event->message->sender_type->value;
        });

        $token = $this->postJson("/api/widget/{$widget->public_key}/session")->json('visitor_token');
        $this->withHeader('X-Visitor-Token', $token)->postJson("/api/widget/{$widget->public_key}/messages", ['body' => 'salam'])->assertCreated();

        // Sent right away, not as queued jobs that a worker picks up later.
        $this->assertInstanceOf(ShouldBroadcastNow::class, new VisitorMessageCreated(Message::withoutGlobalScopes()->first(), 1));
        $order = array_values(array_filter($order, fn (string $e) => $e !== 'visitor'));
        $this->assertSame(['typing:true', 'ai', 'typing:false'], $order);
    }

    public function test_keyword_hits_are_not_cut_by_the_vector_similarity_threshold(): void
    {
        $workspace = $this->createWorkspace();
        $widget = Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        AiCredential::create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'api_key' => 'sk-ant-test-1234567890', 'chat_model' => 'claude-sonnet-5', 'is_default' => true, 'status' => 'valid']);
        $document = KnowledgeDocument::create(['workspace_id' => $workspace->id, 'title' => 'CV', 'disk_path' => 'cv.pdf', 'status' => DocumentStatus::Ready]);
        KnowledgeChunk::create(['document_id' => $document->id, 'workspace_id' => $workspace->id, 'page' => 1, 'chunk_index' => 0, 'content' => 'ƏLAQƏ 055 548 40 70 Könül Hətəmova, tərcüməçi']);
        $fake = Prism::fake([TextResponseFake::make()->withText('055 548 40 70')]);

        $token = $this->postJson("/api/widget/{$widget->public_key}/session")->json('visitor_token');
        // Typed without Azerbaijani letters, and only some of the words appear in the text.
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$widget->public_key}/messages", ['body' => 'konulun elaqe nomresini teqdim edin'])
            ->assertCreated();

        $fake->assertRequest(fn (array $requests) => $this->assertStringContainsString('055 548 40 70', $requests[0]->systemPrompts()[0]->content));
        $this->assertSame('055 548 40 70', Message::withoutGlobalScopes()->where('sender_type', 'ai')->value('body'));
    }

    public function test_an_autofilled_password_is_not_accepted_as_the_platform_embedding_key(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        $this->actingAs($admin)->putJson('/api/admin/settings', ['platform_embedding_key' => 'Sales123!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform_embedding_key');

        $this->actingAs($admin)->putJson('/api/admin/settings', ['platform_embedding_key' => 'sk-proj-abcdefghijklmnopqrstuvwxyz'])->assertOk();
        // The masked value sent back by the form keeps the stored key.
        $this->actingAs($admin)->putJson('/api/admin/settings', ['platform_embedding_key' => '••••wxyz'])->assertOk();
        $this->assertSame('sk-proj-abcdefghijklmnopqrstuvwxyz', PlatformSetting::get('platform_embedding_key'));
    }

    public function test_composer_dev_runs_a_worker_for_every_queue_and_reverb(): void
    {
        $commands = collect(DevCommands::commands())->pluck('command', 'name');

        $this->assertStringContainsString('--queue=ai,default,documents', $commands['worker']);
        $this->assertArrayNotHasKey('queue', $commands->all()); // the default "default"-only listener
        $this->assertSame('php artisan reverb:start', $commands['reverb']);
    }
}
