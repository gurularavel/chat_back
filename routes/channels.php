<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Operator inbox / admin panel of a workspace.
Broadcast::channel('workspace.{workspaceId}', function (User $user, int $workspaceId) {
    return $user->is_superadmin || $user->roleIn($workspaceId) !== null;
});

// Superadmin live flow across all tenants.
Broadcast::channel('admin', fn (User $user) => $user->is_superadmin);

// visitor.{id} channels are authorized by WidgetBroadcastAuthController (visitor token, no user).
