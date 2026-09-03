<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * @return array{0: WorkspaceMember, 1: Project}
 */
function completionProject(): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod3, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    return [$member, $project];
}

test('finishing a task stamps when it was finished, and reopening clears it', function () {
    [$member, $project] = completionProject();

    $task = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'status' => 'todo']);

    expect($task->completed_at)->toBeNull();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $task), ['status' => 'done'])
        ->assertRedirect();

    $finishedAt = $task->refresh()->completed_at;

    expect($finishedAt)->not->toBeNull();

    // A later edit that leaves the status alone must not restamp it, or the
    // board would treat a rename as a fresh completion.
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $task), ['title' => 'Judul baru'])
        ->assertRedirect();

    expect($task->refresh()->completed_at->eq($finishedAt))->toBeTrue();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $task), ['status' => 'in_progress'])
        ->assertRedirect();

    expect($task->refresh()->completed_at)->toBeNull();
});

test('the board carries the completion stamp, newest first', function () {
    [$member, $project] = completionProject();

    $older = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'status' => 'todo', 'position' => 0]);

    $newer = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'status' => 'todo', 'position' => 1]);

    foreach ([$older, $newer] as $task) {
        $this->travel(1)->minutes();

        $this->actingAs($member->user)
            ->withSession(['workspace_id' => $member->workspace_id])
            ->patch(route('tasks.update', $task), ['status' => 'done'])
            ->assertRedirect();
    }

    expect($newer->refresh()->completed_at->gt($older->refresh()->completed_at))->toBeTrue();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page
            ->where(
                'tasks.0.completed_at',
                fn (?string $stamp): bool => $stamp !== null,
            ),
        );
});
