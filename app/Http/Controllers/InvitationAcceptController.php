<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Http\Requests\InvitationAcceptRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public landing page for an invitation link.
 *
 * A new address sets its password here; an address that already has an account
 * only confirms, and is then added to the workspace.
 */
class InvitationAcceptController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        if ($invitation === null) {
            return Inertia::render('invitation/invalid');
        }

        return Inertia::render('invitation/accept', [
            'workspaceName' => $invitation->workspace->name,
            'email' => $invitation->email,
            'roleLabel' => $invitation->role->label(),
            'needsAccount' => ! User::where('email', $invitation->email)->exists(),
            'passwordRules' => Password::defaults()?->toPasswordRulesString(),
            'token' => $token,
        ]);
    }

    public function store(InvitationAcceptRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        abort_if($invitation === null, 410, 'Undangan tidak berlaku lagi.');

        $user = DB::transaction(function () use ($invitation, $request): User {
            $user = User::where('email', $invitation->email)->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $invitation->email,
                    'password' => $request->validated('password'),
                ]);

                $user->forceFill(['email_verified_at' => now()])->save();
            }

            WorkspaceMember::withoutGlobalScopes()->firstOrCreate(
                ['workspace_id' => $invitation->workspace_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'joined_at' => now()],
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureWorkspaceAccess::SESSION_KEY, $invitation->workspace_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Selamat datang di '.$invitation->workspace->name.'.']);

        return to_route('dashboard');
    }

    /**
     * Look the invitation up outside tenant scoping; the visitor has no workspace yet.
     */
    protected function pendingInvitation(string $token): ?Invitation
    {
        $invitation = Invitation::withoutGlobalScopes()
            ->with('workspace')
            ->where('token', $token)
            ->first();

        if ($invitation === null || ! $invitation->isPending() || ! $invitation->workspace->is_active) {
            return null;
        }

        return $invitation;
    }
}
