<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Requester;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;

/**
 * @return array{0: WorkspaceMember, 1: Project}
 */
function requestedProject(WorkspaceRole $role = WorkspaceRole::Manager): array
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

test('a task hands its requester to every task below it, however deep', function () {
    [$member, $project] = requestedProject();

    $requester = Requester::factory()->for($project->workspace)->create();
    $other = Requester::factory()->for($project->workspace)->create();

    $hierarchy = app(TaskHierarchy::class);
    $parent = $hierarchy->create($project, [
        'title' => 'Rancang skema',
        'requester_id' => $requester->id,
    ]);
    $child = $hierarchy->create($project, ['title' => 'Migrasi tabel'], $parent);
    $grandchild = $hierarchy->create($project, [
        'title' => 'Uji migrasi',
        'requester_id' => $other->id,
    ], $child);
    $sibling = $hierarchy->create($project, ['title' => 'Laporan mingguan']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.sync-requester', $parent))
        ->assertRedirect();

    foreach ([$child, $grandchild] as $task) {
        expect($task->refresh()->requester_id)->toBe($requester->id);
    }

    // A task on another branch keeps whatever it had.
    expect($sibling->refresh()->requester_id)->toBeNull();
});

test('a task with no requester of its own is refused', function () {
    [$member, $project] = requestedProject();

    $requester = Requester::factory()->for($project->workspace)->create();

    $hierarchy = app(TaskHierarchy::class);
    $parent = $hierarchy->create($project, ['title' => 'Rancang skema']);
    $child = $hierarchy->create($project, [
        'title' => 'Migrasi tabel',
        'requester_id' => $requester->id,
    ], $parent);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.sync-requester', $parent))
        ->assertStatus(422);

    // The sub task's own requester survives the refusal rather than being wiped.
    expect($child->refresh()->requester_id)->toBe($requester->id);
});

test('somebody who may not edit the task may not sync its requester either', function () {
    [$member, $project] = requestedProject(WorkspaceRole::Viewer);

    $requester = Requester::factory()->for($project->workspace)->create();

    $task = Task::factory()->for($project)->create([
        'title' => 'Rancang skema',
        'requester_id' => $requester->id,
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.sync-requester', $task))
        ->assertForbidden();
});
