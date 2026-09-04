<?php

namespace App\Concerns;

use App\Support\Tenancy;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Existence rules that stop at the tenant boundary.
 *
 * A bare `exists` rule bypasses the global scope, so an id belonging to
 * another company would validate and only fail later — or not at all (7.2
 * rule 5). Every request that accepts a foreign key from the browser builds
 * its rule through here instead.
 */
trait ScopesValidationToWorkspace
{
    /**
     * A row of `$table` that belongs to the active workspace.
     */
    protected function existsInWorkspace(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('workspace_id', app(Tenancy::class)->id());
    }

    /**
     * A value free inside the active workspace, matching a per tenant unique
     * index. Soft deleted rows still count, because the index counts them.
     */
    protected function uniqueInWorkspace(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)
            ->where('workspace_id', app(Tenancy::class)->id());
    }

    /**
     * An org unit inside the active workspace's slice of the master tree.
     *
     * Units are platform-wide, so they carry no `workspace_id`; the boundary
     * is the `path` prefix of the node the workspace was placed on.
     */
    protected function existsAsOrgUnit(): Exists
    {
        $path = app(Tenancy::class)->workspace()?->orgUnitRootPath();

        return Rule::exists('org_units', 'id')->where(
            fn (Builder $query) => $path === null
                ? $query->whereRaw('1 = 0')
                : $query->where('path', 'like', $path.'%'),
        );
    }

    /**
     * A user who is a member of the active workspace.
     */
    protected function existsAsWorkspaceMember(): Exists
    {
        return $this->existsInWorkspace('workspace_members', 'user_id');
    }

    /**
     * A requester on the active workspace's list who is still being offered.
     *
     * Retired rows are excluded on purpose: they stay on the tasks that
     * already name them, but a form that hands one out again would undo the
     * retiring.
     */
    protected function existsAsActiveRequester(): Exists
    {
        return $this->existsInWorkspace('requesters')->where('is_active', true);
    }

    /**
     * A user who is a member of the given project (TSK-4).
     */
    protected function existsAsProjectMember(int $projectId): Exists
    {
        return Rule::exists('project_members', 'user_id')
            ->where('project_id', $projectId);
    }
}
