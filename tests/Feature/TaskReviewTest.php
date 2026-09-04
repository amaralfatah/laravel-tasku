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
use App\Services\TaskHierarchy;

/**
 * A staff member's sub task is reviewed by whoever owns the task above it
 * (TSK-18): submitting hands it up and the reviewer accepts or returns it.
 *
 * The route is offered, not imposed — closing a task never waits on anybody's
 * approval — so what these cover is that the review path works when a team
 * chooses to use it.
 *
 * @return array{workspace: Workspace, project: Project, lead: WorkspaceMember, staff: WorkspaceMember, parent: Task, child: Task}
 */
function reviewProject(): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();

    $lead = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Manager, 'org_unit_id' => $unit->id]);

    $staff = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach([$lead->user_id, $staff->user_id]);

    // The lead owns the parent task; the staff member owns the sub task
    // handed up to them, which is what makes the lead its reviewer.
    $parent = Task::factory()->for($project)->create([
        'workspace_id' => $workspace->id,
        'assignee_id' => $lead->user_id,
        'status' => 'todo',
    ]);

    $child = app(TaskHierarchy::class)->create(
        $project,
        ['title' => 'Sub task', 'assignee_id' => $staff->user_id, 'status' => 'in_progress'],
        $parent,
    );

    return compact('workspace', 'project', 'lead', 'staff', 'parent', 'child');
}

test('a worker closes their own task without waiting for approval', function () {
    ['workspace' => $workspace, 'staff' => $staff, 'child' => $child] = reviewProject();

    $this->actingAs($staff->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('tasks.update', $child), ['status' => 'done'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($child->refresh()->status)->toBe(TaskStatus::Done);
});

test('a worker may still hand the work up for review instead', function () {
    ['workspace' => $workspace, 'staff' => $staff, 'child' => $child] = reviewProject();

    $this->actingAs($staff->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('tasks.update', $child), ['status' => 'review'])
        ->assertRedirect();

    $child->refresh();

    expect($child->status)->toBe(TaskStatus::Review)
        ->and($child->progress)->toBe(100)
        ->and($child->submitted_at)->not->toBeNull();
});

test('submitting a task notifies whoever owns the task above it', function () {
    ['workspace' => $workspace, 'lead' => $lead, 'staff' => $staff, 'child' => $child] = reviewProject();

    $this->actingAs($staff->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('tasks.update', $child), ['status' => 'review'])
        ->assertRedirect();

    expect(Notification::query()
        ->where('user_id', $lead->user_id)
        ->where('type', NotificationType::ReviewRequested)
        ->where('entity_id', $child->id)
        ->exists())->toBeTrue();
});

test('the reviewer approves the work, which closes it', function () {
    ['workspace' => $workspace, 'lead' => $lead, 'staff' => $staff, 'child' => $child] = reviewProject();
    $child->update(['status' => 'review', 'progress' => 100, 'submitted_at' => now()]);

    $this->actingAs($lead->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('tasks.review', $child), ['decision' => 'approve'])
        ->assertRedirect();

    $child->refresh();

    expect($child->status)->toBe(TaskStatus::Done)
        ->and($child->progress)->toBe(100)
        ->and($child->reviewed_at)->not->toBeNull()
        ->and($child->reviewed_by)->toBe($lead->user_id);

    expect(Notification::query()
        ->where('user_id', $staff->user_id)
        ->where('type', NotificationType::ReviewDecided)
        ->where('entity_id', $child->id)
        ->exists())->toBeTrue();
});

test('the reviewer returns the work, which reopens it and posts the note as a comment', function () {
    ['workspace' => $workspace, 'lead' => $lead, 'child' => $child] = reviewProject();
    $child->update(['status' => 'review', 'progress' => 100, 'submitted_at' => now()]);

    $this->actingAs($lead->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('tasks.review', $child), [
            'decision' => 'return',
            'note' => 'Data belum lengkap, cek ulang.',
        ])
        ->assertRedirect();

    $child->refresh();

    expect($child->status)->toBe(TaskStatus::InProgress)
        ->and($child->progress)->toBe(90)
        ->and($child->reviewed_at)->not->toBeNull();

    expect($child->comments()->where('body', 'Data belum lengkap, cek ulang.')->exists())->toBeTrue();
});

test('a worker may not review their own submitted task', function () {
    ['workspace' => $workspace, 'staff' => $staff, 'child' => $child] = reviewProject();
    $child->update(['status' => 'review', 'progress' => 100, 'submitted_at' => now()]);

    $this->actingAs($staff->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('tasks.review', $child), ['decision' => 'approve'])
        ->assertForbidden();

    expect($child->refresh()->status)->toBe(TaskStatus::Review);
});

test('a project leader closes their own leaf task directly', function () {
    ['workspace' => $workspace, 'project' => $project, 'lead' => $lead] = reviewProject();

    // A leaf task, not the parent from reviewProject() — that one has a child
    // and rolls up from it, which is a different rule (TSK-17).
    $leaf = Task::factory()->for($project)->create([
        'workspace_id' => $workspace->id,
        'assignee_id' => $lead->user_id,
        'status' => 'todo',
    ]);

    $this->actingAs($lead->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('tasks.update', $leaf), ['status' => 'done'])
        ->assertRedirect();

    expect($leaf->refresh()->status)->toBe(TaskStatus::Done);
});

test('a parent does not roll up to Done while a child sits in review, only finished', function () {
    ['parent' => $parent, 'child' => $child] = reviewProject();

    $child->update(['status' => 'review', 'progress' => 100]);
    app(TaskHierarchy::class)->syncParentProgress($child->parent);

    expect($parent->refresh()->status)->toBe(TaskStatus::InProgress);

    $child->update(['status' => 'done', 'progress' => 100]);
    app(TaskHierarchy::class)->syncParentProgress($child->parent);

    expect($parent->refresh()->status)->toBe(TaskStatus::Done);
});
