<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * The console runs without a workspace, so these commands have to drop the
 * tenant scope. Dropping every scope instead takes `SoftDeletes` with it, which
 * is what let deleted rows back into a reminder run and a progress backfill.
 */
function scopeFixture(): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    return [$project, $member];
}

test('the due soon run skips a deleted task and still reaches a live one', function () {
    [$project, $member] = scopeFixture();

    $live = Task::factory()->for($project)->create([
        'assignee_id' => $member->user_id,
        'due_date' => now()->subDay(),
        'status' => TaskStatus::InProgress,
    ]);

    $deleted = Task::factory()->for($project)->create([
        'assignee_id' => $member->user_id,
        'due_date' => now()->subDay(),
        'status' => TaskStatus::InProgress,
    ]);

    $deleted->delete();

    $this->artisan('notifications:due-soon')->assertExitCode(0);

    $reminded = Notification::query()
        ->where('type', NotificationType::DueSoon)
        ->pluck('entity_id');

    expect($reminded)->toContain($live->id)
        ->and($reminded)->not->toContain($deleted->id);
});

test('the progress backfill leaves a deleted task alone', function () {
    [$project] = scopeFixture();

    $parent = Task::factory()->for($project)->create([
        'progress' => 0,
        'status' => TaskStatus::Todo,
    ]);

    Task::factory()->for($project)->create([
        'parent_task_id' => $parent->id,
        'progress' => 100,
        'status' => TaskStatus::Done,
    ]);

    // Creating the sub task already rolled the parent up through the observer,
    // so it is put back by hand: the command is what must not touch it again.
    $parent->refresh()->forceFill(['progress' => 0, 'status' => TaskStatus::Todo])->saveQuietly();

    $parent->delete();

    $this->artisan('task:sync-progress', ['--project' => $project->id])->assertExitCode(0);

    // A live parent would have been pulled up to its sub task's 100%.
    expect($parent->fresh()->progress)->toBe(0);
});

test('the progress backfill skips a deleted project entirely', function () {
    [$project] = scopeFixture();

    $parent = Task::factory()->for($project)->create([
        'progress' => 0,
        'status' => TaskStatus::Todo,
    ]);

    Task::factory()->for($project)->create([
        'parent_task_id' => $parent->id,
        'progress' => 100,
        'status' => TaskStatus::Done,
    ]);

    // Creating the sub task already rolled the parent up through the observer,
    // so it is put back by hand: the command is what must not touch it again.
    $parent->refresh()->forceFill(['progress' => 0, 'status' => TaskStatus::Todo])->saveQuietly();

    $project->delete();

    $this->artisan('task:sync-progress')->assertExitCode(0);

    expect($parent->fresh()->progress)->toBe(0);
});
