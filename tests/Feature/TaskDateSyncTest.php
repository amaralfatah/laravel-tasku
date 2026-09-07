<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;

/**
 * @return array{0: WorkspaceMember, 1: Project}
 */
function datedProject(WorkspaceRole $role = WorkspaceRole::Manager): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    return [$member, $project];
}

test('a task hands its dates to every task below it, however deep', function () {
    [$member, $project] = datedProject();

    $hierarchy = app(TaskHierarchy::class);
    $parent = $hierarchy->create($project, [
        'title' => 'Rancang skema',
        'start_date' => '2026-03-02',
        'due_date' => '2026-03-31',
    ]);
    $child = $hierarchy->create($project, ['title' => 'Migrasi tabel'], $parent);
    $grandchild = $hierarchy->create($project, [
        'title' => 'Uji migrasi',
        'start_date' => '2025-01-01',
        'due_date' => '2025-01-05',
    ], $child);
    $other = $hierarchy->create($project, ['title' => 'Laporan mingguan']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.sync-dates', $parent))
        ->assertRedirect();

    foreach ([$child, $grandchild] as $task) {
        expect($task->refresh()->start_date->toDateString())->toBe('2026-03-02');
        expect($task->due_date->toDateString())->toBe('2026-03-31');
    }

    // A task on another branch keeps whatever it had.
    expect($other->refresh()->start_date)->toBeNull();
});

test('a task with no dates of its own is refused', function () {
    [$member, $project] = datedProject();

    $hierarchy = app(TaskHierarchy::class);
    $parent = $hierarchy->create($project, ['title' => 'Rancang skema']);
    $child = $hierarchy->create($project, [
        'title' => 'Migrasi tabel',
        'start_date' => '2026-03-02',
        'due_date' => '2026-03-31',
    ], $parent);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.sync-dates', $parent))
        ->assertStatus(422);

    // The sub task's own dates survive the refusal rather than being wiped.
    expect($child->refresh()->start_date->toDateString())->toBe('2026-03-02');
});

test('somebody who may not edit the task may not sync it either', function () {
    [$member, $project] = datedProject(WorkspaceRole::Viewer);

    $task = Task::factory()->for($project)->create([
        'title' => 'Rancang skema',
        'start_date' => '2026-03-02',
        'due_date' => '2026-03-31',
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.sync-dates', $task))
        ->assertForbidden();
});
