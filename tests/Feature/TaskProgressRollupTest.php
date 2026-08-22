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
function progressProject(): array
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

function subtaskOf(Task $parent, string $title): Task
{
    // Through the service, so path and depth are the real ones a sub task gets.
    return app(TaskHierarchy::class)->create(
        $parent->project,
        ['title' => $title, 'status' => 'todo', 'progress' => 0],
        $parent,
    );
}

test('finishing a sub task moves the parent progress, nobody types a number', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => 'Induk']);

    $first = subtaskOf($parent, 'Anak satu');
    subtaskOf($parent, 'Anak dua');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $first), ['status' => 'done'])
        ->assertRedirect();

    $parent->refresh();

    expect($parent->progress)->toBe(50);
    expect($parent->status->value)->toBe('in_progress');
});

test('a parent whose sub tasks are all done is done itself', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => 'Induk']);

    $first = subtaskOf($parent, 'Anak satu');
    $second = subtaskOf($parent, 'Anak dua');

    foreach ([$first, $second] as $child) {
        $this->actingAs($member->user)
            ->withSession(['workspace_id' => $member->workspace_id])
            ->patch(route('tasks.update', $child), ['status' => 'done'])
            ->assertRedirect();
    }

    $parent->refresh();

    expect($parent->progress)->toBe(100);
    expect($parent->status->value)->toBe('done');
});

test('progress climbs past the direct parent to the grandparent', function () {
    [$member, $project] = progressProject();

    $root = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => 'Kakek']);

    $parent = subtaskOf($root, 'Induk');
    $child = subtaskOf($parent, 'Anak');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $child), ['status' => 'done'])
        ->assertRedirect();

    expect($parent->refresh()->progress)->toBe(100);
    expect($root->refresh()->progress)->toBe(100);
});

test('a status set by hand on a parent loses to its sub tasks', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => 'Induk']);

    $first = subtaskOf($parent, 'Anak satu');
    subtaskOf($parent, 'Anak dua');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $first), ['status' => 'done'])
        ->assertRedirect();

    // Marking the parent done would otherwise force its progress to 100 while
    // one sub task is still open.
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $parent), ['status' => 'done'])
        ->assertRedirect();

    $parent->refresh();

    expect($parent->progress)->toBe(50);
    expect($parent->status->value)->toBe('in_progress');
});

test('the backfill command recomputes parents written before the rule', function () {
    [, $project] = progressProject();

    $parent = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => 'Induk']);

    $first = subtaskOf($parent, 'Anak satu');
    subtaskOf($parent, 'Anak dua');

    $first->forceFill(['status' => 'done', 'progress' => 100])->saveQuietly();
    $parent->forceFill(['status' => 'done', 'progress' => 100])->saveQuietly();

    $this->artisan('task:sync-progress')->assertSuccessful();

    $parent->refresh();

    expect($parent->progress)->toBe(50);
    expect($parent->status->value)->toBe('in_progress');
});

test('deleting the only unfinished sub task lifts the parent to done', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => 'Induk']);

    $done = subtaskOf($parent, 'Sudah');
    $pending = subtaskOf($parent, 'Belum');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $done), ['status' => 'done'])
        ->assertRedirect();

    expect($parent->refresh()->progress)->toBe(50);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->delete(route('tasks.destroy', $pending))
        ->assertRedirect();

    expect($parent->refresh()->progress)->toBe(100);
});
