<?php

namespace App\Http\Controllers;

use App\Actions\InviteToWorkspace;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Invitation\InvitationStoreRequest;
use App\Models\Invitation;
use App\Models\WorkspaceMember;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Workspace-side invitation management (ORG-6, ORG-7).
 */
class InvitationController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function store(InvitationStoreRequest $request, InviteToWorkspace $inviter): RedirectResponse
    {
        $this->authorize('manage', WorkspaceMember::class);

        $inviter->handle(
            $this->tenancy->workspace(),
            $request->validated('email'),
            WorkspaceRole::from($request->validated('role')),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Undangan dikirim.']);

        return back();
    }

    /**
     * Resend an outstanding invitation with a fresh token and expiry.
     */
    public function resend(Request $request, Invitation $invitation, InviteToWorkspace $inviter): RedirectResponse
    {
        $this->authorize('manage', WorkspaceMember::class);
        abort_unless($invitation->accepted_at === null, 410);

        $inviter->handle(
            $this->tenancy->workspace(),
            $invitation->email,
            $invitation->role,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Undangan dikirim ulang.']);

        return back();
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $this->authorize('manage', WorkspaceMember::class);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Undangan dibatalkan.']);

        return back();
    }
}
