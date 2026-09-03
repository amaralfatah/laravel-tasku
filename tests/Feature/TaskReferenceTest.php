<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;

/**
 * @return array{0: WorkspaceMember, 1: Project}
 */
function referenceProject(string $key = 'GROWMATE'): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Manager, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create(['key' => $key]);
    $project->members()->attach($member->user_id);

    return [$member, $project];
}

test('a task reads as the project key plus its WBS number', function () {
    [, $project] = referenceProject();

    $task = app(TaskHierarchy::class)->create($project, ['title' => 'Rollout alur existing']);

    expect($task->reference)->toBe('GROWMATE-1');
});

test('a sub task carries the WBS number of its own branch', function () {
    [, $project] = referenceProject('SUB');

    $hierarchy = app(TaskHierarchy::class);
    $parent = $hierarchy->create($project, ['title' => 'Induk']);
    $hierarchy->create($project, ['title' => 'Anak satu'], $parent);
    $second = $hierarchy->create($project, ['title' => 'Anak dua'], $parent);

    expect($second->reference)->toBe('SUB-1.2');
});

test('the key comes from the task own project', function () {
    [, $first] = referenceProject('AAA');
    [, $second] = referenceProject('BBB');

    $hierarchy = app(TaskHierarchy::class);

    expect([
        $hierarchy->create($first, ['title' => 'Satu'])->reference,
        $hierarchy->create($first, ['title' => 'Dua'])->reference,
        $hierarchy->create($second, ['title' => 'Punya proyek lain'])->reference,
    ])->toBe(['AAA-1', 'AAA-2', 'BBB-1']);
});

test('renumbering a branch moves the reference with it', function () {
    [, $project] = referenceProject('REN');

    $hierarchy = app(TaskHierarchy::class);
    $first = $hierarchy->create($project, ['title' => 'Dihapus']);
    $second = $hierarchy->create($project, ['title' => 'Naik satu tingkat']);

    expect($second->reference)->toBe('REN-2');

    $hierarchy->delete($first);

    expect($second->refresh()->reference)->toBe('REN-1');
});

test('the board ships the reference to the frontend', function () {
    [$member, $project] = referenceProject('BRD');

    app(TaskHierarchy::class)->create($project, ['title' => 'Kartu papan']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.0.reference', 'BRD-1')
            ->where('tasks.0.title', 'Kartu papan'));
});
