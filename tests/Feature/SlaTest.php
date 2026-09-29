<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Widget;
use App\Models\Workspace;
use App\Notifications\SlaBreachedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Prism\Prism\Facades\Prism;
use Tests\TestCase;

class SlaTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Widget $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->workspace = $this->createWorkspace();
        $this->widget = Widget::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->first();
    }

    public function test_reply_within_the_target_meets_the_sla(): void
    {
        $this->enableSla(10);
        $operator = $this->onlineOperator();

        $conversation = $this->handedOffConversation();
        $this->assertNotNull($conversation->handoff_at);
        $this->assertEquals($conversation->handoff_at->copy()->addMinutes(10)->timestamp, $conversation->sla_due_at->timestamp);

        $this->travel(4)->minutes();
        $this->actingInWorkspace($operator, $this->workspace)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Salam, buyurun'])
            ->assertCreated();

        $conversation->refresh();
        $this->assertNotNull($conversation->first_response_at);
        $this->assertNull($conversation->sla_breached_at);

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('sla.total', 1)
            ->assertJsonPath('sla.met', 1)
            ->assertJsonPath('sla.target_minutes', 10)
            ->assertJsonPath('first_response.avg_seconds', 240);
    }

    public function test_missed_deadline_is_flagged_once_and_alerts_admins_and_the_assignee(): void
    {
        Notification::fake();
        $this->enableSla(5);
        $assignee = $this->onlineOperator();
        $otherOperator = $this->addMember($this->workspace);

        $conversation = $this->handedOffConversation();
        $this->assertSame($assignee->id, $conversation->assigned_user_id);

        $this->travel(4)->minutes();
        $this->artisan('sla:check')->assertSuccessful();
        $this->assertNull($conversation->fresh()->sla_breached_at);

        $this->travel(2)->minutes();
        $this->artisan('sla:check')->assertSuccessful();
        $this->artisan('sla:check')->assertSuccessful();

        $this->assertNotNull($conversation->fresh()->sla_breached_at);
        Notification::assertSentToTimes($this->workspace->owner, SlaBreachedNotification::class, 1);
        Notification::assertSentToTimes($assignee, SlaBreachedNotification::class, 1);
        Notification::assertNotSentTo($otherOperator, SlaBreachedNotification::class);

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('sla.breached', 1)
            ->assertJsonPath('sla.waiting_breached', 1);

        // A late reply is recorded but stays a breach.
        $this->actingInWorkspace($assignee, $this->workspace)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Bağışlayın, gecikdim'])
            ->assertCreated();
        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->getJson('/api/dashboard')
            ->assertJsonPath('sla.met', 0)
            ->assertJsonPath('sla.waiting_breached', 0);
    }

    public function test_returning_the_chat_to_the_ai_before_the_deadline_drops_the_clock(): void
    {
        Notification::fake();
        $this->enableSla(5);
        $operator = $this->onlineOperator();
        $conversation = $this->handedOffConversation();

        $this->actingInWorkspace($operator, $this->workspace)->postJson("/api/conversations/{$conversation->id}/claim")->assertOk();
        $this->actingInWorkspace($operator, $this->workspace)->postJson("/api/conversations/{$conversation->id}/return-to-ai")->assertOk();

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Ai, $conversation->status);
        $this->assertNull($conversation->sla_due_at);

        $this->travel(10)->minutes();
        $this->artisan('sla:check')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_plans_without_sla_track_response_time_but_set_no_deadline(): void
    {
        $this->onlineOperator();

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson('/api/workspace', ['sla_first_response_minutes' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sla_first_response_minutes');

        $conversation = $this->handedOffConversation();
        $this->assertNotNull($conversation->handoff_at);
        $this->assertNull($conversation->sla_due_at);

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('sla', null);
    }

    public function test_admin_sets_the_target_on_a_plan_with_sla(): void
    {
        $this->enableSla(null);

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson('/api/workspace', ['sla_first_response_minutes' => 15])
            ->assertOk();

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->getJson('/api/me')
            ->assertJsonPath('current_workspace.sla_first_response_minutes', 15)
            ->assertJsonPath('current_workspace.sla_available', true);

        $operator = $this->addMember($this->workspace);
        $this->actingInWorkspace($operator, $this->workspace)
            ->patchJson('/api/workspace', ['sla_first_response_minutes' => 1])
            ->assertForbidden();
    }

    private function enableSla(?int $minutes): void
    {
        Subscription::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)
            ->update(['plan_id' => Plan::where('code', 'enterprise')->value('id')]);
        $this->workspace->update(['sla_first_response_minutes' => $minutes]);
    }

    /** Visitor asks for a human, which hands the chat off right away. */
    private function handedOffConversation(): Conversation
    {
        Prism::fake([]);
        $token = $this->postJson("/api/widget/{$this->widget->public_key}/session", ['host' => 'example.com'])->json('visitor_token');
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Operatorla danışmaq istəyirəm'])
            ->assertCreated();

        $conversation = Conversation::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(ConversationStatus::PendingHuman, $conversation->status);

        return $conversation;
    }

    private function onlineOperator(): User
    {
        $user = $this->addMember($this->workspace, WorkspaceRole::Operator);
        $this->workspace->members()->updateExistingPivot($user->id, ['is_online' => true, 'last_seen_at' => now()]);

        return $user;
    }
}
