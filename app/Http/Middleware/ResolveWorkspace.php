<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace from the X-Workspace header (or the user's current one),
 * verifies membership and scopes all BelongsToWorkspace models to it.
 * Must run before SubstituteBindings (see bootstrap/app.php priority).
 */
class ResolveWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $id = $request->header('X-Workspace') ?: $user->current_workspace_id;
        $workspace = $id ? Workspace::find($id) : null;
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        $role = $user->roleIn($workspace);
        if (! $role && ! $user->is_superadmin) {
            abort(403, 'You are not a member of this workspace.');
        }

        if ($workspace->isSuspended() && ! $user->is_superadmin) {
            abort(423, __('billing.workspace_suspended'));
        }

        $this->current->set($workspace);
        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_role', $role ?? WorkspaceRole::Owner);

        return $next($request);
    }
}
