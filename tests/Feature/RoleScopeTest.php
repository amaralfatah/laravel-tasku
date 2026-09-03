<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * BOD-1, BOD-2 and BOD-3 have identical abilities; only the slice of the org
 * tree they reach differs, and that slice comes from their own unit.
 */

/**
 * @return array{workspace: Workspace, marketing: OrgUnit, engineering: OrgUnit, backend: OrgUnit}
 */
function twoBranchWorkspace(): array
{
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->rootOf($workspace)->create(['name' => 'Divisi']);
    $marketing = OrgUnit::factory()->childOf($root)->create(['name' => 'Marketing']);
    $engineering = OrgUnit::factory()->childOf($root)->create(['name' => 'Engineering']);
    $backend = OrgUnit::factory()->childOf($engineering)->create(['name' => 'Backend']);

    return compact('workspace', 'marketing', 'engineering', 'backend');
}

test('every leader role gets the same menu, an ODS gets none of it', function (WorkspaceRole $role, bool $leads) {
    ['workspace' => $workspace, 'engineering' => $engineering] = twoBranchWorkspace();

    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, $role)
        ->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.membership.can_manage', $leads)
            ->where('tenancy.membership.can_monitor', $leads)
        );
})->with([
    'kepala divisi' => [WorkspaceRole::Owner, true],
    'kepala sub divisi' => [WorkspaceRole::Manager, true],
    'asisten' => [WorkspaceRole::Manager, true],
    'ods' => [WorkspaceRole::Member, false],
]);

test('a leader sees only the projects hanging off their own unit', function () {
    ['workspace' => $workspace, 'marketing' => $marketing, 'backend' => $backend] = twoBranchWorkspace();

    $mine = Project::factory()->in($backend)->create(['name' => 'API gateway']);
    Project::factory()->in($marketing)->create(['name' => 'Kampanye panen']);

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading(OrgUnit::whereName('Engineering')->firstOrFail(), WorkspaceRole::Manager)
        ->create();

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('projects', 1)
            ->where('projects.0.name', $mine->name)
        );
});

test('kepala divisi sees every project in the workspace', function () {
    ['workspace' => $workspace, 'marketing' => $marketing, 'backend' => $backend] = twoBranchWorkspace();

    Project::factory()->in($backend)->create();
    Project::factory()->in($marketing)->create();

    $kadiv = WorkspaceMember::factory()
        ->for($workspace)
        ->owner()
        ->create();

    $this->actingAs($kadiv->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('projects', 2));
});

test('an asisten may create a project in their own subtree but not outside it', function () {
    ['workspace' => $workspace, 'marketing' => $marketing, 'backend' => $backend] = twoBranchWorkspace();

    $asisten = WorkspaceMember::factory()
        ->for($workspace)
        ->leading(OrgUnit::whereName('Engineering')->firstOrFail(), WorkspaceRole::Manager)
        ->create();

    $session = ['workspace_id' => $workspace->id];

    $this->actingAs($asisten->user)->withSession($session)
        ->post(route('projects.store'), [
            'name' => 'Refactor billing',
            'org_unit_id' => $backend->id,
        ])
        ->assertRedirect();

    $this->actingAs($asisten->user)->withSession($session)
        ->post(route('projects.store'), [
            'name' => 'Brosur',
            'org_unit_id' => $marketing->id,
        ])
        ->assertForbidden();
});

test('a leader cannot touch a member outside their subtree', function () {
    ['workspace' => $workspace, 'marketing' => $marketing, 'backend' => $backend] = twoBranchWorkspace();

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading(OrgUnit::whereName('Engineering')->firstOrFail(), WorkspaceRole::Manager)
        ->create();

    $outsider = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $marketing->id]);

    $insider = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $backend->id]);

    $session = ['workspace_id' => $workspace->id];

    $this->actingAs($leader->user)->withSession($session)
        ->patch(route('members.update', $outsider), ['role' => WorkspaceRole::Manager->value])
        ->assertForbidden();

    $this->actingAs($leader->user)->withSession($session)
        ->patch(route('members.update', $insider), ['role' => WorkspaceRole::Manager->value])
        ->assertRedirect();

    expect($insider->refresh()->role)->toBe(WorkspaceRole::Manager);
});

test('nobody may hand out a role above their own', function () {
    ['workspace' => $workspace, 'backend' => $backend] = twoBranchWorkspace();

    // The workspace keeps a BOD-1 so the last top role guard is not what fails.
    WorkspaceMember::factory()->for($workspace)->owner()->create();

    $asisten = WorkspaceMember::factory()
        ->for($workspace)
        ->leading(OrgUnit::whereName('Engineering')->firstOrFail(), WorkspaceRole::Manager)
        ->create();

    $subordinate = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $backend->id]);

    $this->actingAs($asisten->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('members.update', $subordinate), ['role' => WorkspaceRole::Owner->value])
        ->assertSessionHasErrors('role');

    expect($subordinate->refresh()->role)->toBe(WorkspaceRole::Member);
});

test('an asisten cannot promote themselves', function () {
    ['workspace' => $workspace] = twoBranchWorkspace();

    WorkspaceMember::factory()->for($workspace)->owner()->create();

    $asisten = WorkspaceMember::factory()
        ->for($workspace)
        ->leading(OrgUnit::whereName('Engineering')->firstOrFail(), WorkspaceRole::Manager)
        ->create();

    $this->actingAs($asisten->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('members.update', $asisten), ['role' => WorkspaceRole::Owner->value])
        ->assertSessionHasErrors('role');

    expect($asisten->refresh()->role)->toBe(WorkspaceRole::Manager);
});

test('an ODS is kept out of the roster and the organisation page', function () {
    ['workspace' => $workspace, 'backend' => $backend] = twoBranchWorkspace();

    $ods = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $backend->id]);

    $session = ['workspace_id' => $workspace->id];

    $this->actingAs($ods->user)->withSession($session)
        ->get(route('members.index'))->assertForbidden();

    $this->actingAs($ods->user)->withSession($session)
        ->get(route('organization.index'))->assertForbidden();
});

test('a leader shapes their own branch and opens it at their own unit', function () {
    ['workspace' => $workspace] = twoBranchWorkspace();

    $engineering = OrgUnit::whereName('Engineering')->firstOrFail();

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, WorkspaceRole::Manager)
        ->create();

    $this->actingAs($leader->user)->withSession(['workspace_id' => $workspace->id]);

    // The page opens on the single node their scope hangs off, not on the
    // master roots the operator sees.
    $this->get(route('organization.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('units', 1)
            ->where('units.0.name', 'Engineering')
            ->where('can.manage', true)
            ->where('can.manage_roots', false)
        );

    $this->post(route('org-units.store'), ['name' => 'Platform', 'parent_id' => $engineering->id])
        ->assertRedirect();

    expect(OrgUnit::whereName('Platform')->firstOrFail()->parent_id)->toBe($engineering->id);

    $this->getJson(route('org-units.search', ['q' => 'Engineering']))
        ->assertOk()
        ->assertJsonPath('units.0.name', 'Engineering');
});

test('a leader may not shape the tree outside their own branch', function () {
    ['workspace' => $workspace, 'marketing' => $marketing] = twoBranchWorkspace();

    $engineering = OrgUnit::whereName('Engineering')->firstOrFail();

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, WorkspaceRole::Manager)
        ->create();

    $this->actingAs($leader->user)->withSession(['workspace_id' => $workspace->id]);

    // A sibling branch, a root of the master tree, and their own scope root's
    // parent are all off limits.
    $this->post(route('org-units.store'), ['name' => 'Diselundupkan', 'parent_id' => $marketing->id])
        ->assertForbidden();
    $this->post(route('org-units.store'), ['name' => 'Entitas baru'])
        ->assertForbidden();
    $this->patch(route('org-units.update', $marketing), ['name' => 'Dirampas'])
        ->assertForbidden();

    expect(OrgUnit::whereName('Diselundupkan')->exists())->toBeFalse()
        ->and($marketing->refresh()->name)->toBe('Marketing');
});

test('a unit mirrored from SAP stays the operators, even inside a leaders branch', function () {
    ['workspace' => $workspace] = twoBranchWorkspace();

    $engineering = OrgUnit::whereName('Engineering')->firstOrFail();
    $imported = OrgUnit::factory()
        ->childOf($engineering)
        ->create(['name' => 'Backend SAP', 'external_id' => '50000001']);

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, WorkspaceRole::Manager)
        ->create();

    $this->actingAs($leader->user)->withSession(['workspace_id' => $workspace->id]);

    // A re-import would overwrite the change, so it is refused rather than
    // silently reverted later.
    $this->patch(route('org-units.update', $imported), ['name' => 'Diubah'])->assertForbidden();
    $this->delete(route('org-units.destroy', $imported))->assertForbidden();

    expect($imported->refresh()->name)->toBe('Backend SAP');
});

test('a leader searches inside their scope but not outside it', function () {
    ['workspace' => $workspace, 'marketing' => $marketing] = twoBranchWorkspace();

    $engineering = OrgUnit::whereName('Engineering')->firstOrFail();

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, WorkspaceRole::Manager)
        ->create();

    // Nothing outside the branch is reachable.
    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Marketing']))
        ->assertOk()
        ->assertJsonCount(0, 'units');

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Backend']))
        ->assertOk()
        ->assertJsonCount(1, 'units')
        ->assertJsonPath('units.0.trail', ['Divisi', 'Engineering']);

    expect($marketing->name)->toBe('Marketing');
});

test('an ODS cannot reach the org tree endpoints at all', function () {
    ['workspace' => $workspace] = twoBranchWorkspace();

    $engineering = OrgUnit::whereName('Engineering')->firstOrFail();

    $ods = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, WorkspaceRole::Member)
        ->create();

    $this->actingAs($ods->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Backend']))
        ->assertForbidden();
});

test('the menu survives a page that loads the org unit without its path', function () {
    // Regression: /monitoring/me loads the viewer's own membership with a
    // column subset that omits `path`, which used to make the shared props
    // report the leader as leading nobody and collapse the sidebar.
    ['workspace' => $workspace, 'engineering' => $engineering] = twoBranchWorkspace();

    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($engineering, WorkspaceRole::Manager)
        ->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.membership.can_monitor', true)
        );
});
