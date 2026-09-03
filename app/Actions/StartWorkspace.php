<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\WorkspaceController;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\OrgUnitTree;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Starts a workspace for someone who belongs to none, and makes them its Owner.
 *
 * This is the self-serve path: a freelancer or a small team signs up and is
 * working a moment later, without an operator provisioning anything. The
 * operator's own path ({@see WorkspaceController::store})
 * still exists for a company whose first Owner is invited by email.
 *
 * A workspace needs a node of the org tree to hang off, so one is created for
 * it, named after the workspace and owned by the customer rather than SAP —
 * it carries no `external_id`, so no import will ever overwrite it. The Owner
 * is placed in that node, which is what gives them a scope to work in.
 */
class StartWorkspace
{
    public function __construct(
        protected OrgUnitTree $tree,
        protected Tenancy $tenancy,
    ) {}

    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = Workspace::create(['name' => $name]);

            // Created outside any tenant context on purpose: the org tree is
            // platform master data and carries no workspace_id.
            $root = $this->tenancy->withoutScope(fn () => $this->tree->create([
                'name' => $name,
                'type' => 'company',
            ]));

            $workspace->update(['root_org_unit_id' => $root->id]);

            $this->tenancy->forWorkspace($workspace, fn () => WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Owner,
                'org_unit_id' => $root->id,
                'joined_at' => now(),
            ]));

            return $workspace->refresh();
        });
    }
}
