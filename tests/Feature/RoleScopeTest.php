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

test('a leader does not shape the structure, they only search it', function () {
    ['workspace' => $workspace] = twoBranchWorkspace();

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading(OrgUnit::whereName('Engineering')->firstOrFail(), WorkspaceRole::Manager)
        ->create();

    // The org tree is platform master data the operator maintains, so the
    // structure page is closed even to the leader of a branch.
    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('organization.index'))
        ->assertForbidden();

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Engineering']))
        ->assertOk()
        ->assertJsonPath('units.0.name', 'Engineering');
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
