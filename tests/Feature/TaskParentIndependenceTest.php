<?php

use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use Inertia\Testing\AssertableInertia;

/**
 * The two halves of a task with sub tasks are owned by different people.
 *
 * Its **percentage** is owned by its children (TSK-17): there is no control
 * anywhere for typing a number on a task that has sub tasks, so finishing one
 * is the only thing that can move the bar above it.
 *
 * Its **status** is owned by whoever is looking at the board. Deriving that too
 * is what broke: a parent whose sub tasks were all done could not be dragged
 * out of Selesai, because every save recomputed the status straight back and
 * the card snapped home with no error and no message.
 *
 * So a parent may sit at 100% and still be moved to To Do, and one whose sub
 * tasks are all finished is not closed until somebody closes it.
 *
 * @return array{0: WorkspaceMember, 1: Project}
 */
function progressProject(): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Manager, 'org_unit_id' => $unit->id]);

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

test('a finished parent can be dragged to any board column and stays there', function () {
    // The report this rule change was made for: GRO-25, done with three done
    // sub tasks, would not leave the Selesai column.
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Optimasi',
        'status' => TaskStatus::Done,
        'progress' => 100,
    ]);

    foreach (['Anak satu', 'Anak dua'] as $title) {
        subtaskOf($parent, $title)->forceFill([
            'status' => TaskStatus::Done,
            'progress' => 100,
        ])->save();
    }

    foreach (['todo', 'in_progress', 'review', 'done'] as $column) {
        $this->actingAs($member->user)
            ->withSession(['workspace_id' => $member->workspace_id])
            ->post(route('tasks.move', $parent), ['status' => $column])
            ->assertRedirect();

        $parent->refresh();

        // The status moved; the percentage stayed with the sub tasks, which
        // are still both done. Moving a parent to To Do does not un-finish
        // the work under it.
        expect($parent->status->value)->toBe($column)
            ->and($parent->progress)->toBe(100);
    }
});

test('finishing a sub task moves the percentage above it, nobody types a number', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Induk',
        'status' => TaskStatus::Todo,
        'progress' => 0,
    ]);

    $first = subtaskOf($parent, 'Anak satu');
    subtaskOf($parent, 'Anak dua');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $first), ['status' => 'done'])
        ->assertRedirect();

    $parent->refresh();

    // Half the sub tasks are done, so the bar reads 50 — but nobody said the
    // task itself had started, so its column has not changed.
    expect($parent->progress)->toBe(50)
        ->and($parent->status)->toBe(TaskStatus::Todo);
});

test('the percentage climbs past the direct parent to the grandparent', function () {
    [$member, $project] = progressProject();

    $root = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Kakek',
        'progress' => 0,
    ]);

    $parent = subtaskOf($root, 'Induk');
    $child = subtaskOf($parent, 'Anak');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $child), ['status' => 'done'])
        ->assertRedirect();

    expect($parent->refresh()->progress)->toBe(100)
        ->and($root->refresh()->progress)->toBe(100);
});

test('every sub task finishing does not close the task above them', function () {
    // Deliberate: closing a task is a person's statement, not an arithmetic
    // result. The bar reaching 100 is the prompt to do it.
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Induk',
        'status' => TaskStatus::InProgress,
        'progress' => 40,
    ]);

    foreach ([subtaskOf($parent, 'Anak satu'), subtaskOf($parent, 'Anak dua')] as $child) {
        $this->actingAs($member->user)
            ->withSession(['workspace_id' => $member->workspace_id])
            ->patch(route('tasks.update', $child), ['status' => 'done'])
            ->assertRedirect();
    }

    $parent->refresh();

    expect($parent->progress)->toBe(100)
        ->and($parent->status)->toBe(TaskStatus::InProgress);
});

test('a status set by hand on a parent is the one that is kept', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Induk',
        'status' => TaskStatus::Todo,
        'progress' => 0,
    ]);

    subtaskOf($parent, 'Anak satu');
    subtaskOf($parent, 'Anak dua');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $parent), ['status' => 'done'])
        ->assertRedirect();

    $parent->refresh();

    // Done does *not* force 100 here: TSK-16 governs a task's own two fields,
    // and this task's percentage is its children's, who have not started.
    expect($parent->status)->toBe(TaskStatus::Done)
        ->and($parent->progress)->toBe(0);
});

test('deleting the only unfinished sub task lifts the percentage above it', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Induk',
        'status' => TaskStatus::InProgress,
    ]);

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

    $parent->refresh();

    // The mass delete fires no model events, so the service asks for the
    // rollup itself — and the status is still the one a person set.
    expect($parent->progress)->toBe(100)
        ->and($parent->status)->toBe(TaskStatus::InProgress);
});

test('the board reports the parent percentage and the sub task counts', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Induk',
        'status' => TaskStatus::Todo,
        'progress' => 0,
    ]);

    subtaskOf($parent, 'Anak satu')->forceFill([
        'status' => TaskStatus::Done,
        'progress' => 100,
    ])->save();
    subtaskOf($parent, 'Anak dua');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks.0.progress', 50)
            ->where('tasks.0.rollup_progress', 50)
            ->where('tasks.0.children_count', 2)
            ->where('tasks.0.done_children_count', 1)
            ->etc()
        );
});

test('renaming a sub task from the parent modal touches only its title', function () {
    [$member, $project] = progressProject();

    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'title' => 'Induk',
    ]);

    $first = subtaskOf($parent, 'Anak satu');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $first), ['status' => 'done'])
        ->assertRedirect();

    // What the inline rename sends: the title on its own.
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $first), ['title' => 'Anak satu diubah'])
        ->assertRedirect();

    $first->refresh();

    expect($first->title)->toBe('Anak satu diubah')
        ->and($first->status)->toBe(TaskStatus::Done)
        ->and($first->progress)->toBe(100);
});
