<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Widget;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_provisions_workspace_with_trial_and_widget(): void
    {
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/auth/register', [
            'name' => 'Aysel',
            'email' => 'aysel@example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'company' => 'Aysel MMC',
        ])->assertCreated();

        $user = User::where('email', 'aysel@example.com')->firstOrFail();
        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('current_workspace.name', 'Aysel MMC')
            ->assertJsonPath('current_workspace.role', 'owner')
            ->assertJsonPath('current_workspace.subscription.status', 'trialing')
            ->assertJsonPath('current_workspace.service_active', true);

        $this->assertSame(1, Widget::withoutGlobalScopes()->where('workspace_id', $user->current_workspace_id)->count());
    }

    public function test_route_model_binding_is_scoped_to_the_current_workspace(): void
    {
        $a = $this->createWorkspace(name: 'A');
        $b = $this->createWorkspace(name: 'B');

        $widgetB = Widget::withoutGlobalScopes()->where('workspace_id', $b->id)->first();
        $visitor = Visitor::create(['workspace_id' => $b->id, 'widget_id' => $widgetB->id, 'token' => str_repeat('x', 64)]);
        $conversationB = Conversation::create(['workspace_id' => $b->id, 'widget_id' => $widgetB->id, 'visitor_id' => $visitor->id]);

        $this->actingInWorkspace($a->owner, $a)->getJson("/api/conversations/{$conversationB->id}")->assertNotFound();
        $this->actingInWorkspace($a->owner, $a)->getJson("/api/widgets/{$widgetB->id}")->assertNotFound();
        $this->actingInWorkspace($a->owner, $a)->getJson('/api/widgets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_user_cannot_select_a_workspace_they_do_not_belong_to(): void
    {
        $a = $this->createWorkspace(name: 'A');
        $b = $this->createWorkspace(name: 'B');

        $this->actingInWorkspace($a->owner, $b)->getJson('/api/widgets')->assertForbidden();
    }

    public function test_operators_cannot_reach_admin_endpoints(): void
    {
        $workspace = $this->createWorkspace();
        $operator = $this->addMember($workspace, WorkspaceRole::Operator);

        $this->actingInWorkspace($operator, $workspace)->getJson('/api/conversations')->assertOk();
        $this->actingInWorkspace($operator, $workspace)->getJson('/api/ai-credentials')->assertForbidden();
        $this->actingInWorkspace($operator, $workspace)->getJson('/api/billing')->assertForbidden();
    }

    public function test_superadmin_endpoints_require_superadmin(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace->owner)->getJson('/api/admin/overview')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        $this->actingAs($admin)->getJson('/api/admin/overview')->assertOk()->assertJsonStructure(['mrr', 'workspaces', 'ai']);
        $this->actingAs($admin)->getJson('/api/admin/workspaces')->assertOk()->assertJsonPath('total', 1);
    }
}
