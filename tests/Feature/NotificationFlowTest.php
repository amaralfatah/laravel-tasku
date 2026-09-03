<?php

use App\Enums\NotificationType;
use App\Enums\WorkspaceRole;
use App\Models\Notification;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use Inertia\Testing\AssertableInertia;

test('opening a notification lands on the task itself, even a nested one', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $root = Task::factory()->for($project)->create();
    // A sub task never shows up as a board card (BRD-4), so the board would be
    // a dead end for this notification.
    $child = app(TaskHierarchy::class)->create(
        $project,
        ['title' => 'Sub task', 'assignee_id' => $member->user_id],
        $root,
    );

    $notification = Notification::create([
        'user_id' => $member->user_id,
        'workspace_id' => $workspace->id,
        'type' => NotificationType::TaskAssigned,
        'entity_type' => 'task',
        'entity_id' => $child->id,
        'message' => 'Sub task ditugaskan kepada Anda',
        'is_read' => false,
    ]);

    $session = ['workspace_id' => $workspace->id];

    $this->actingAs($member->user)
        ->withSession($session)
        ->post(route('notifications.read', $notification))
        ->assertRedirect(route('projects.list', ['project' => $project->id, 'task' => $child->id]));

    expect($notification->fresh()->is_read)->toBeTrue();

    $this->actingAs($member->user)
        ->withSession($session)
        ->get(route('projects.list', ['project' => $project->id, 'task' => $child->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/list')
            ->where('focusTaskId', $child->id)
        );
});

test('someone elses notification cannot be read', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()->for($workspace)->create(['org_unit_id' => $unit->id]);
    $other = WorkspaceMember::factory()->for($workspace)->create(['org_unit_id' => $unit->id]);

    $task = Task::factory()->for(Project::factory()->in($unit))->create();

    $notification = Notification::create([
        'user_id' => $other->user_id,
        'workspace_id' => $workspace->id,
        'type' => NotificationType::TaskAssigned,
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'message' => 'Bukan untuk Anda',
        'is_read' => false,
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('notifications.read', $notification))
        ->assertForbidden();

    expect($notification->fresh()->is_read)->toBeFalse();
});
