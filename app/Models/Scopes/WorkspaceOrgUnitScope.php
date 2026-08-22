<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts the org tree to the slice the active workspace runs.
 *
 * Org units are platform-wide master data imported from SAP, so they carry no
 * `workspace_id`. A workspace instead points at the node it runs, and every
 * unit inside that subtree shares its `path` prefix — the same comparison the
 * member scope already uses.
 *
 * Without an active workspace (console, super admin) the scope is a no-op and
 * the whole master tree is visible, so those contexts must scope their own
 * queries. A workspace with no root unit yet sees nothing rather than
 * everything.
 *
 * @implements Scope<Model>
 */
class WorkspaceOrgUnitScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspace = app(Tenancy::class)->workspace();

        if ($workspace === null) {
            return;
        }

        $path = $workspace->orgUnitRootPath();

        if ($path === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('path'), 'like', $path.'%');
    }
}
