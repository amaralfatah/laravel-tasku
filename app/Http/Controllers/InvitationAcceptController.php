<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Http\Requests\InvitationAcceptRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public landing page for an invitation link.
 *
 * An address without an account sets its password here and is signed in. An
 * address that already has one must sign in first: the link alone proves
 * nothing about who is holding it, so accepting it may never authenticate an
 * existing account.
 */
class InvitationAcceptController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        if ($invitation === null) {
            return Inertia::render('invitation/invalid');
        }

        $existing = User::where('email', $invitation->email)->first();
        $current = $request->user();

        if ($existing !== null && $current === null) {
            // Come back here once they have signed in.
            $request->session()->put('url.intended', route('invitation.show', $token));
        }

        return Inertia::render('invitation/accept', [
            'workspaceName' => $invitation->workspace->name,
            'email' => $invitation->email,
            'roleLabel' => $invitation->role->label(),
            'needsAccount' => $existing === null,
            'needsLogin' => $existing !== null && $current === null,
            'signedInAs' => $current === null ? null : [
                'name' => $current->name,
                'email' => $current->email,
            ],
            'isWrongAccount' => $existing !== null && $current !== null && $current->id !== $existing->id,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'token' => $token,
        ]);
    }

    public function store(InvitationAcceptRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->pendingInvitation($token);

        abort_if($invitation === null, 410, 'Undangan tidak berlaku lagi.');

        $existing = User::where('email', $invitation->email)->first();

        if ($existing !== null && $request->user()?->id !== $existing->id) {
            $request->session()->put('url.intended', route('invitation.show', $token));

            return to_route('login')->withErrors([
                'email' => 'Masuk dengan '.$invitation->email.' untuk menerima undangan ini.',
            ]);
        }

        $user = DB::transaction(function () use ($invitation, $request, $existing): User {
            $user = $existing;

            if ($user === null) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $invitation->email,
                    'password' => $request->validated('password'),
                ]);

                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $alreadyMember = WorkspaceMember::withoutGlobalScopes()
                ->where('workspace_id', $invitation->workspace_id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $alreadyMember) {
                // `workspace_id` is guarded and there is no active workspace on
                // this public route, so it is set by hand rather than filled
                // from the tenant context.
                $member = new WorkspaceMember([
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    // Land in the inviter's unit: scope follows placement in
                    // the org tree, so an unplaced leader would lead nobody.
                    'org_unit_id' => $this->inviterUnitId($invitation),
                    'joined_at' => now(),
                ]);

                $member->workspace_id = $invitation->workspace_id;
                $member->save();
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        // Only a brand new account is signed in here; an existing one is
        // already authenticated by the check above.
        if ($existing === null) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $request->session()->put(EnsureWorkspaceAccess::SESSION_KEY, $invitation->workspace_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Selamat datang di '.$invitation->workspace->name.'.']);

        return to_route('dashboard');
    }

    /**
     * Unit the inviter sits in, which the new member inherits.
     *
     * An invitation sent by the platform super admin, or by a BOD-1 who is not
     * placed anywhere, leaves the unit empty for a leader to fill in.
     */
    protected function inviterUnitId(Invitation $invitation): ?int
    {
        if ($invitation->invited_by === null) {
            return null;
        }

        return WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $invitation->workspace_id)
            ->where('user_id', $invitation->invited_by)
            ->value('org_unit_id');
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
