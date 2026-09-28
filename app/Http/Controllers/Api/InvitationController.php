<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\Billing\PlanLimits;
use App\Services\Workspaces\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Invitation::whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get(['id', 'email', 'role', 'expires_at', 'created_at']),
        ]);
    }

    public function store(Request $request, PlanLimits $limits): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Operator->value])],
        ]);
        $email = strtolower($data['email']);

        if ($workspace->members()->where('email', $email)->exists()) {
            abort(422, __('team.already_member'));
        }

        Invitation::where('email', $email)->whereNull('accepted_at')->delete();
        $limits->ensureSeatAvailable($workspace);

        $token = Str::random(48);
        $invitation = Invitation::create([
            'workspace_id' => $workspace->id,
            'invited_by' => $request->user()->id,
            'email' => $email,
            'role' => $data['role'],
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)->notify(new WorkspaceInvitation($invitation, $token));
        AuditLog::record('invitation.created', $invitation, ['email' => $email]);

        return response()->json(['id' => $invitation->id], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        Invitation::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Public: invitation preview for the accept page. */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findPending($token);

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'workspace' => $invitation->workspace()->withoutGlobalScopes()->value('name'),
            'user_exists' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    /** Public: accept as a logged-in user with the invited email, or register a new account. */
    public function accept(Request $request, string $token, WorkspaceService $workspaces): JsonResponse
    {
        $invitation = $this->findPending($token);
        $user = $request->user();

        if ($user && strtolower($user->email) !== $invitation->email) {
            abort(403, __('team.invite_other_email'));
        }

        if (! $user) {
            if (User::where('email', $invitation->email)->exists()) {
                abort(409, __('team.login_to_accept'));
            }

            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'locale' => app()->getLocale(),
            ]);
            $user->markEmailAsVerified(); // they proved ownership through the invite link
            Auth::guard('web')->login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        DB::transaction(function () use ($invitation, $user, $workspaces) {
            $workspace = $invitation->workspace()->withoutGlobalScopes()->first();
            $workspaces->addMember($workspace, $user, $invitation->role);
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            $invitation->update(['accepted_at' => now()]);
        });

        return response()->json(['ok' => true, 'workspace_id' => $invitation->workspace_id]);
    }

    private function findPending(string $token): Invitation
    {
        $invitation = Invitation::withoutGlobalScopes()->where('token', hash('sha256', $token))->first();

        if (! $invitation || ! $invitation->isPending()) {
            abort(404, __('team.invite_invalid'));
        }

        return $invitation;
    }
}
