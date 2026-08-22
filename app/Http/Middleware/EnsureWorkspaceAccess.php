<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active workspace for the request and verifies membership.
 *
 * The workspace id lives in the session so it survives navigation. Every
 * request re-checks the membership row, so a revoked member loses access
 * immediately instead of at the next login.
 *
 * A super admin is a special case: they belong to no workspace, but may open
 * any of them, so they are handed a virtual Owner membership for whichever
 * workspace they are looking at.
 *
 * The `optional` mode resolves the same context but never redirects. It is
 * used by pages that must stay reachable without a workspace — the workspace
 * roster a super admin manages, which is also where someone lands when the
 * platform has no active workspace at all.
 */
class EnsureWorkspaceAccess
{
    public const SESSION_KEY = 'workspace_id';

    public const OPTIONAL = 'optional';

    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $optional = $mode === self::OPTIONAL;
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user->is_super_admin) {
            return $this->handleSuperAdmin($request, $user, $next, $optional);
        }

        $member = $this->resolveMembership($request);

        if ($member === null || ! $member->workspace->is_active) {
            $request->session()->forget(self::SESSION_KEY);

            return $optional ? $next($request) : redirect()->route('workspace.none');
        }

        $workspace = $member->workspace;

        $request->session()->put(self::SESSION_KEY, $workspace->id);
        $this->tenancy->set($workspace, $member);

        return $next($request);
    }

    /**
     * Let a super admin into any active workspace with full rights.
     */
    protected function handleSuperAdmin(Request $request, User $user, Closure $next, bool $optional): Response
    {
        $workspace = $this->resolveWorkspaceForSuperAdmin($request);

        if ($workspace === null) {
            return $optional ? $next($request) : redirect()->route('workspace.none');
        }

        $request->session()->put(self::SESSION_KEY, $workspace->id);
        $this->tenancy->set($workspace, $this->virtualMembership($workspace, $user), superAdmin: true);

        return $next($request);
    }

    /**
     * An unsaved Owner membership, so every policy treats a super admin as the
     * workspace owner without a row ever appearing in the member roster.
     */
    protected function virtualMembership(Workspace $workspace, User $user): WorkspaceMember
    {
        $member = new WorkspaceMember;

        $member->workspace_id = $workspace->id;
        $member->user_id = $user->id;
        $member->role = WorkspaceRole::Bod1;

        return $member;
    }

    protected function resolveWorkspaceForSuperAdmin(Request $request): ?Workspace
    {
        $sessionId = $request->session()->get(self::SESSION_KEY);

        $workspace = $sessionId === null
            ? null
            : Workspace::query()->where('is_active', true)->whereKey($sessionId)->first();

        return $workspace ?? Workspace::query()->where('is_active', true)->orderBy('name')->first();
    }

    /**
     * Membership for the session workspace, falling back to the first active one.
     */
    protected function resolveMembership(Request $request): ?WorkspaceMember
    {
        $memberships = $request->user()
            ->workspaceMembers()
            ->with('workspace')
            ->get()
            ->filter(fn (WorkspaceMember $member): bool => (bool) $member->workspace?->is_active);

        $sessionId = $request->session()->get(self::SESSION_KEY);

        return $memberships->firstWhere('workspace_id', $sessionId)
            ?? $memberships->first();
    }
}
