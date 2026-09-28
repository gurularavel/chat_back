<?php

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceService;
use App\Support\CurrentWorkspace;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Owner + workspace (trial subscription, AI settings, default widget). */
    protected function createWorkspace(?User $owner = null, string $name = 'Acme'): Workspace
    {
        $this->seed(PlanSeeder::class);
        $owner ??= User::factory()->create();

        return app(WorkspaceService::class)->create($owner, $name);
    }

    protected function addMember(Workspace $workspace, WorkspaceRole $role = WorkspaceRole::Operator): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->addMember($workspace, $user, $role);

        return $user;
    }

    /** Authenticated JSON requests against a workspace. */
    protected function actingInWorkspace(User $user, Workspace $workspace): static
    {
        app(CurrentWorkspace::class)->set(null);

        return $this->actingAs($user)->withHeader('X-Workspace', (string) $workspace->id);
    }

    /** Deterministic fake embedding: 1536 dims, direction chosen by seed. */
    protected function fakeVector(int $seed): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[$seed % 1536] = 1.0;

        return $vector;
    }
}
