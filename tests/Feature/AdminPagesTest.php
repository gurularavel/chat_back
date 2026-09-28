<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\AiUsageLog;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Widget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every superadmin screen's API must load with real data in the tables
 * (joins across tenants are easy to get wrong, e.g. ambiguous columns).
 */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $workspace = $this->createWorkspace();
        $widget = Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        $visitor = Visitor::create(['workspace_id' => $workspace->id, 'widget_id' => $widget->id, 'token' => str_repeat('a', 64), 'current_url' => 'https://shop.az/']);
        $conversation = Conversation::create(['workspace_id' => $workspace->id, 'widget_id' => $widget->id, 'visitor_id' => $visitor->id, 'last_message_at' => now(), 'was_handed_off' => true]);
        Message::create(['workspace_id' => $workspace->id, 'conversation_id' => $conversation->id, 'sender_type' => 'visitor', 'body' => 'Salam']);
        KnowledgeDocument::create(['workspace_id' => $workspace->id, 'title' => 'Doc', 'disk_path' => 'x.pdf', 'status' => DocumentStatus::Ready]);

        foreach ([true, false] as $success) {
            AiUsageLog::create([
                'workspace_id' => $workspace->id, 'conversation_id' => $conversation->id, 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5',
                'type' => 'chat', 'input_tokens' => 120, 'output_tokens' => 30, 'latency_ms' => 900, 'success' => $success, 'error' => $success ? null : '401',
            ]);
        }
        AuditLog::create(['workspace_id' => $workspace->id, 'action' => 'test.action']);

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_superadmin' => true])->save();
    }

    public static function endpoints(): array
    {
        return [
            'overview' => ['/api/admin/overview'],
            'workspaces' => ['/api/admin/workspaces'],
            'workspaces search' => ['/api/admin/workspaces?search=ac&status=trialing'],
            'workspace detail' => ['/api/admin/workspaces/{workspace}'],
            'conversations' => ['/api/admin/conversations?handed_off=1'],
            'usage' => ['/api/admin/usage?days=30'],
            'usage per workspace' => ['/api/admin/usage?days=7&workspace_id={workspace}'],
            'documents' => ['/api/admin/documents?workspace_id={workspace}'],
            'plans' => ['/api/admin/plans'],
            'payments' => ['/api/admin/payments'],
            'settings' => ['/api/admin/settings'],
            'audit logs' => ['/api/admin/audit-logs?action=test'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_superadmin_page_api_loads_with_data(string $url): void
    {
        $workspaceId = Workspace::value('id');

        $this->actingAs($this->admin)
            ->getJson(str_replace('{workspace}', (string) $workspaceId, $url))
            ->assertOk();
    }

    public function test_usage_aggregates_are_correct(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/usage?days=30')
            ->assertJsonPath('by_workspace.0.calls', 2)
            ->assertJsonPath('by_workspace.0.errors', 1)
            ->assertJsonPath('by_model.0.input_tokens', 240)
            ->assertJsonCount(1, 'recent_errors');
    }
}
