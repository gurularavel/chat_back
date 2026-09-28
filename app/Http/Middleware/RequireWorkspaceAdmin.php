<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Owner / admin only (operators can use the inbox but not settings). */
class RequireWorkspaceAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->attributes->get('workspace_role');

        if (! $role instanceof WorkspaceRole || ! $role->canManage()) {
            abort(403, 'Only workspace owners and admins can do this.');
        }

        return $next($request);
    }
}
