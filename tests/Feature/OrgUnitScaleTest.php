<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * After the SAP import a workspace holds tens of thousands of org units. No
 * page may ship that list, and every unit picker has to work through search
 * instead. These tests guard the payloads that used to hold the whole tree.
 */

/**
 * @return array{workspace: Workspace, leader: WorkspaceMember, root: OrgUnit}
 */
function wideWorkspace(int $children = 40): array
{
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->for($workspace)->create(['name' => 'Perusahaan']);

    for ($i = 1; $i <= $children; $i++) {
        OrgUnit::factory()->childOf($root)->create(['name' => "Unit {$i}"]);
    }

    $leader = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Bod1)
        ->create();

    return compact('workspace', 'leader', 'root');
}

test('the organisation page ships only the top level, never the whole tree', function () {
    ['workspace' => $workspace, 'leader' => $leader] = wideWorkspace();

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('organization.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('units', 1)
            ->where('units.0.children_count', 40)
            ->where('maxDepth', OrgUnit::MAX_DEPTH)
        );
});

test('a branch arrives only when it is opened', function () {
    ['workspace' => $workspace, 'leader' => $leader, 'root' => $root] = wideWorkspace();

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.children', $root))
        ->assertOk()
        ->assertJsonCount(40, 'units');
});

test('the members and projects pages send a picker, not a list of units', function (string $route) {
    ['workspace' => $workspace, 'leader' => $leader, 'root' => $root] = wideWorkspace();

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('orgUnits')
            ->where('unitPicker.can_choose', true)
            ->where('unitPicker.default.id', $root->id)
            ->where('unitPicker.default.name', 'Perusahaan')
            ->etc()
        );
})->with(['members.index', 'projects.index']);

test('search never returns more than one screenful', function () {
    ['workspace' => $workspace, 'leader' => $leader] = wideWorkspace(60);

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Unit']))
        ->assertOk()
        ->assertJsonCount(30, 'units');
});

test('search ignores a term too short to narrow anything down', function () {
    ['workspace' => $workspace, 'leader' => $leader] = wideWorkspace(5);

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'U']))
        ->assertOk()
        ->assertJsonCount(0, 'units');
});

test('search matches regardless of case', function () {
    ['workspace' => $workspace, 'leader' => $leader] = wideWorkspace(2);

    $this->actingAs($leader->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'perusahaan']))
        ->assertOk()
        ->assertJsonCount(1, 'units')
        ->assertJsonPath('units.0.name', 'Perusahaan');
});
