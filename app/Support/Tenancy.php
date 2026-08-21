<?php

namespace App\Support;

use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * Holds the workspace resolved for the current request.
 *
 * Bound as a singleton so the global scope, policies and controllers all read
 * the same value without threading it through every call.
 */
class Tenancy
{
    protected ?Workspace $workspace = null;

    protected ?WorkspaceMember $member = null;

    /**
     * True when the membership is the virtual one handed to a super admin
     * rather than a real row in `workspace_members`.
     */
    protected bool $superAdmin = false;

    public function set(Workspace $workspace, ?WorkspaceMember $member = null, bool $superAdmin = false): void
    {
        $this->workspace = $workspace;
        $this->member = $member;
        $this->superAdmin = $superAdmin;
    }

    public function forget(): void
    {
        $this->workspace = null;
        $this->member = null;
        $this->superAdmin = false;
    }

    /**
     * Whether the current request is a super admin looking into a workspace
     * they are not actually a member of.
     */
    public function actingAsSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function workspace(): ?Workspace
    {
        return $this->workspace;
    }

    /**
     * Membership row of the authenticated user in the active workspace.
     */
    public function member(): ?WorkspaceMember
    {
        return $this->member;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function check(): bool
    {
        return $this->workspace !== null;
    }

    /**
     * Run a callback with tenant scoping disabled, e.g. inside console commands.
     */
    public function withoutScope(callable $callback): mixed
    {
        $workspace = $this->workspace;
        $member = $this->member;
        $superAdmin = $this->superAdmin;
        $this->forget();

        try {
            return $callback();
        } finally {
            $this->workspace = $workspace;
            $this->member = $member;
            $this->superAdmin = $superAdmin;
        }
    }

    /**
     * Run a callback scoped to the given workspace, then restore the previous one.
     */
    public function forWorkspace(Workspace $workspace, callable $callback): mixed
    {
        $previousWorkspace = $this->workspace;
        $previousMember = $this->member;
        $previousSuperAdmin = $this->superAdmin;
        $this->set($workspace);

        try {
            return $callback();
        } finally {
            $this->workspace = $previousWorkspace;
            $this->member = $previousMember;
            $this->superAdmin = $previousSuperAdmin;
        }
    }
}
