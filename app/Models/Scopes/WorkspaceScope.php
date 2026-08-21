<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every tenant-owned model to the workspace of the current request.
 *
 * @implements Scope<Model>
 *
 * Without an active workspace (console, super admin routes) the scope is a
 * no-op, so those contexts must scope their queries explicitly.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(Tenancy::class)->id();

        if ($workspaceId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
