<?php

namespace App\Concerns;

use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
     * A user who is a member of the active workspace.
     */
    protected function existsAsWorkspaceMember(): Exists
    {
        return $this->existsInWorkspace('workspace_members', 'user_id');
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
