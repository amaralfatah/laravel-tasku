<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use Inertia\Testing\AssertableInertia;

/**
 * A project with one member, one root task and one sub task under it.
 *
 * @return array{0: WorkspaceMember, 1: Project}
 */
function searchProject(): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Manager, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $hierarchy = app(TaskHierarchy::class);
    $parent = $hierarchy->create($project, ['title' => 'Rancang skema']);
    $hierarchy->create($project, ['title' => 'Migrasi tabel panen'], $parent);
    $hierarchy->create($project, ['title' => 'Laporan mingguan']);

    return [$member, $project];
}

/**
 * @return array<int, string>
 */
function searchTitles(WorkspaceMember $member, Project $project, string $term): array
{
    $titles = [];

    test()->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.list', ['project' => $project, 'search' => $term]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$titles): void {
            $titles = array_column($page->toArray()['props']['tasks'], 'title');
        });

    return $titles;
}

test('searching a sub task keeps the sub task and the parent above it', function () {
    // A bare title match left the board empty and the list rootless, since
    // the board only draws depth 0 and the row had no parent to sit under.
    [$member, $project] = searchProject();

    expect(searchTitles($member, $project, 'panen'))
        ->toBe(['Rancang skema', 'Migrasi tabel panen']);
});

test('searching a parent brings its sub tasks along', function () {
    [$member, $project] = searchProject();

    expect(searchTitles($member, $project, 'skema'))
        ->toBe(['Rancang skema', 'Migrasi tabel panen']);
});

test('a search that matches nothing on the branch drops the whole branch', function () {
    [$member, $project] = searchProject();

    expect(searchTitles($member, $project, 'mingguan'))
        ->toBe(['Laporan mingguan']);
});

test('the search ignores case', function () {
    [$member, $project] = searchProject();

    expect(searchTitles($member, $project, 'PANEN'))
        ->toBe(['Rancang skema', 'Migrasi tabel panen']);
});
