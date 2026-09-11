<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Run the backfill the way `php artisan migrate` would.
 */
function runCompletedAtBackfill(): void
{
    $migration = require database_path('migrations/2026_09_11_080206_backfill_completed_at_on_finished_tasks.php');

    $migration->up();
}

function backfillProject(): Project
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();

    WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Manager, 'org_unit_id' => $unit->id]);

    return Project::factory()->in($unit)->create();
}

/**
 * Write a task straight into the table, past the observer that would stamp it —
 * which is how the seeded and imported rows arrived.
 */
function unstampedTask(Project $project, string $status, ?string $dueDate, string $updatedAt): Task
{
    $task = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'status' => $status]);

    DB::table('tasks')->where('id', $task->id)->update([
        'completed_at' => null,
        'due_date' => $dueDate,
        'updated_at' => $updatedAt,
    ]);

    return $task->refresh();
}

test('a finished task with no stamp is closed on its due date', function () {
    $project = backfillProject();

    $task = unstampedTask($project, 'done', '2025-06-30', '2025-08-01 09:00:00');

    runCompletedAtBackfill();

    expect($task->refresh()->completed_at?->toDateString())->toBe('2025-06-30');
});

test('cancelled tasks are backfilled too', function () {
    $project = backfillProject();

    $task = unstampedTask($project, 'cancelled', '2025-06-30', '2025-08-01 09:00:00');

    runCompletedAtBackfill();

    expect($task->refresh()->completed_at?->toDateString())->toBe('2025-06-30');
});

test('a due date past the last edit is a deadline the task never reached, so the edit stands in', function () {
    $project = backfillProject();

    // Closing in the future would sort the card to the top of the column and
    // keep it there, since nothing older than a month ever ages out of it.
    $task = unstampedTask($project, 'done', '2030-01-01', '2025-08-01 09:00:00');

    runCompletedAtBackfill();

    expect($task->refresh()->completed_at?->toDateString())->toBe('2025-08-01');
});

test('a finished task with no due date falls back to its last edit', function () {
    $project = backfillProject();

    $task = unstampedTask($project, 'done', null, '2025-08-01 09:00:00');

    runCompletedAtBackfill();

    expect($task->refresh()->completed_at?->toDateString())->toBe('2025-08-01');
});

test('a stamp the observer already wrote is left alone', function () {
    $project = backfillProject();

    $task = Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'status' => 'done']);

    DB::table('tasks')->where('id', $task->id)->update([
        'completed_at' => '2025-09-09 10:00:00',
        'due_date' => '2025-06-30',
    ]);

    runCompletedAtBackfill();

    expect($task->refresh()->completed_at?->toDateString())->toBe('2025-09-09');
});

test('unfinished tasks keep their empty stamp', function () {
    $project = backfillProject();

    $task = unstampedTask($project, 'in_progress', '2025-06-30', '2025-08-01 09:00:00');

    runCompletedAtBackfill();

    expect($task->refresh()->completed_at)->toBeNull();
});
