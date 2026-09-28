<?php

namespace Tests\Feature;

use App\Models\AiCredential;
use App\Models\Invitation;
use App\Notifications\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Embedding;
use Tests\TestCase;

class TeamAndKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitations_respect_seat_limit_and_can_be_accepted(): void
    {
        Notification::fake();
        $workspace = $this->createWorkspace(); // trial: 3 seats, owner uses 1

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson('/api/invitations', ['email' => 'op1@example.com', 'role' => 'operator'])->assertCreated();
        $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson('/api/invitations', ['email' => 'op2@example.com', 'role' => 'operator'])->assertCreated();
        $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson('/api/invitations', ['email' => 'op3@example.com', 'role' => 'operator'])
            ->assertStatus(402)
            ->assertJsonPath('limit', 'seats');

        $token = null;
        Notification::assertSentOnDemand(WorkspaceInvitation::class, function (WorkspaceInvitation $n) use (&$token) {
            if ($n->invitation->email === 'op1@example.com') {
                $token = $n->token;
            }

            return true;
        });

        $this->app['auth']->forgetGuards(); // the invitee is a guest

        $this->postJson("/api/invitations/{$token}/accept", [
            'name' => 'Operator One',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])->assertOk();

        $this->assertSame(2, $workspace->members()->count());
        $this->assertNotNull(Invitation::withoutGlobalScopes()->where('email', 'op1@example.com')->value('accepted_at'));
    }

    public function test_ai_keys_are_encrypted_masked_and_verified(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('pong'),
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray($this->fakeVector(1))]),
        ]);
        $workspace = $this->createWorkspace();
        $key = 'sk-test-1234567890abcdef';

        $response = $this->actingInWorkspace($workspace->owner, $workspace)->postJson('/api/ai-credentials', [
            'provider' => 'openai',
            'api_key' => $key,
            'chat_model' => 'gpt-4.1-mini',
        ])->assertCreated();

        $response->assertJsonMissingPath('data.api_key')
            ->assertJsonPath('data.masked_key', 'sk-t…cdef')
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.is_default', true);

        $raw = DB::table('ai_credentials')->value('api_key');
        $this->assertNotSame($key, $raw);
        $this->assertSame($key, AiCredential::withoutGlobalScopes()->first()->api_key);
    }

    public function test_invalid_key_is_marked_invalid(): void
    {
        Prism::fake([]); // returns empty responses → force failure through a throwing fake below
        $workspace = $this->createWorkspace();

        Prism::shouldReceive('text')->andThrow(new \RuntimeException('401 Unauthorized'));

        $this->actingInWorkspace($workspace->owner, $workspace)->postJson('/api/ai-credentials', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-invalid-key-000',
            'chat_model' => 'claude-haiku-4-5',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.last_error', '401 Unauthorized');
    }
}
