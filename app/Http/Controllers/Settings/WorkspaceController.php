<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\WorkspaceIdentityRequest;
use App\Models\OrgUnit;
use App\Models\Workspace;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's own settings, for the Owner who runs it.
 *
 * Split from `App\Http\Controllers\WorkspaceController`, which is the operator
 * console: that one hands entities out, places them in the org tree and
 * switches them off. This one owns nothing but the entity's identity — its
 * name and its logo — so an Owner never has to ask an operator to fix a typo
 * in their own company name.
 */
class WorkspaceController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function edit(): Response
    {
        $workspace = $this->activeWorkspace();

        return Inertia::render('settings/workspace', [
            'workspace' => [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'logo' => $workspace->logo,
            ],
        ]);
    }

    public function update(WorkspaceIdentityRequest $request): RedirectResponse
    {
        $workspace = $this->activeWorkspace();

        $workspace->name = $request->validated('name');

        $this->syncLogo($request, $workspace);

        $workspace->save();

        $this->renameOwnRoot($workspace);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Workspace diperbarui.']);

        return to_route('workspace.settings.edit');
    }

    /**
     * The active workspace, once the viewer is allowed to change it.
     *
     * The `workspace` middleware has already resolved the tenant, so the route
     * carries no workspace parameter: an Owner only ever administers the
     * entity they are standing in, and there is no id in the URL to point
     * somewhere else.
     */
    protected function activeWorkspace(): Workspace
    {
        $workspace = $this->tenancy->workspace();

        abort_if($workspace === null, 404);

        $this->authorize('manageIdentity', $workspace);

        return $workspace;
    }

    /**
     * Store a newly uploaded logo, or clear the current one. Mirrors
     * `ProfileController::syncAvatar()` — same disk, same delete-then-store
     * order, so an old file never outlives the row that pointed at it.
     */
    protected function syncLogo(WorkspaceIdentityRequest $request, Workspace $workspace): void
    {
        $upload = $request->file('logo');
        $shouldRemove = $request->boolean('remove_logo');

        if ($upload === null && ! $shouldRemove) {
            return;
        }

        if ($workspace->logo_path !== null) {
            Storage::disk('public')->delete($workspace->logo_path);
        }

        $stored = $upload?->store('workspace-logos', 'public');

        $workspace->logo_path = is_string($stored) ? $stored : null;
    }

    /**
     * A self-serve workspace drew its own root unit and named it after itself
     * (see `StartWorkspace`), so leaving that node behind on a rename would
     * show the old name all over the structure page. A root mirrored from SAP
     * carries an `external_id` and stays the operator's — a re-import would
     * overwrite the change anyway.
     */
    protected function renameOwnRoot(Workspace $workspace): void
    {
        $root = OrgUnit::withoutGlobalScopes()
            ->whereKey($workspace->root_org_unit_id)
            ->whereNull('external_id')
            ->first();

        $root?->update(['name' => $workspace->name]);
    }
}
