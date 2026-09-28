<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Models\Widget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Widget $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->createWorkspace();
        $this->widget = Widget::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->first();
    }

    public function test_admin_manages_departments_and_their_operators(): void
    {
        $operator = $this->addMember($this->workspace);

        $id = $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->postJson('/api/departments', ['name' => 'Sales', 'user_ids' => [$operator->id]])
            ->assertCreated()
            ->assertJsonPath('data.user_ids', [$operator->id])
            ->json('data.id');

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/departments/{$id}", ['name' => 'Billing', 'user_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.name', 'Billing')
            ->assertJsonPath('data.user_ids', []);

        // Operators can list, not manage.
        $this->actingInWorkspace($operator, $this->workspace)->getJson('/api/departments')->assertOk()->assertJsonCount(1, 'data');
        $this->actingInWorkspace($operator, $this->workspace)->postJson('/api/departments', ['name' => 'X'])->assertForbidden();
    }

    public function test_members_of_another_workspace_cannot_be_added(): void
    {
        $stranger = User::factory()->create();

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->postJson('/api/departments', ['name' => 'Sales', 'user_ids' => [$stranger->id]])
            ->assertStatus(422);
    }

    public function test_visitor_chosen_department_routes_the_handoff_to_its_operators(): void
    {
        $sales = $this->onlineOperator();
        $support = $this->onlineOperator();
        $department = Department::create(['workspace_id' => $this->workspace->id, 'name' => 'Support']);
        $department->members()->sync([$support->id]);
        // Sales has fewer chats, but is not in the chosen department.
        Department::create(['workspace_id' => $this->workspace->id, 'name' => 'Sales'])->members()->sync([$sales->id]);

        $this->getJson("/api/widget/{$this->widget->public_key}/config")
            ->assertOk()
            ->assertJsonPath('departments.0.name', 'Support')
            ->assertJsonCount(2, 'departments');

        Prism::fake([]);
        $token = $this->postJson("/api/widget/{$this->widget->public_key}/session", ['host' => 'example.com'])->json('visitor_token');
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", [
                'body' => 'Operatorla danışmaq istəyirəm',
                'department_id' => $department->id,
            ])
            ->assertCreated()
            ->assertJsonPath('conversation.department_id', $department->id);

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($department->id, $conversation->department_id);
        $this->assertSame($support->id, $conversation->assigned_user_id);

        $this->actingInWorkspace($support, $this->workspace)
            ->getJson("/api/conversations?filter=all&department_id={$department->id}")
            ->assertOk()
            ->assertJsonPath('data.0.department.name', 'Support');
    }

    public function test_foreign_department_id_is_ignored(): void
    {
        $other = $this->createWorkspace(name: 'Other');
        $foreign = Department::create(['workspace_id' => $other->id, 'name' => 'Theirs']);

        Prism::fake([]);
        $token = $this->postJson("/api/widget/{$this->widget->public_key}/session", ['host' => 'example.com'])->json('visitor_token');
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Operatorla danışmaq istəyirəm', 'department_id' => $foreign->id])
            ->assertCreated();

        $this->assertNull(Conversation::withoutGlobalScopes()->firstOrFail()->department_id);
    }

    private function onlineOperator(): User
    {
        $user = $this->addMember($this->workspace, WorkspaceRole::Operator);
        $this->workspace->members()->updateExistingPivot($user->id, ['is_online' => true, 'last_seen_at' => now()]);

        return $user;
    }
}
