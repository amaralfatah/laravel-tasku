<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

test('my own task page lets me edit the tasks of projects i belong to', function () {
    // MON-7: this is the landing page, so the work has to be doable here.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->for($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);
    Task::factory()->for($project)->create(['assignee_id' => $member->user_id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/person')
            ->where('isSelf', true)
            ->where('tasks.0.can_edit', true)
            ->where('tasks.0.tasks.0.can_edit', true)
            ->has('statuses')
            ->has('priorities')
        );
});

test('an asisten may edit the tasks of a project inside their own subtree', function () {
    // Scope is authority now: a leader owns delivery everywhere below their
    // own unit, without having to join every project.
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->for($workspace)->create();
    $child = OrgUnit::factory()->childOf($root)->create();

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Bod3)
        ->create();

    $worker = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $child->id]);

    $project = Project::factory()->in($child)->create();
    Task::factory()->for($project)->create(['assignee_id' => $worker->user_id]);

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.person', $worker))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks.0.can_edit', true)
            ->where('tasks.0.tasks.0.can_edit', true)
        );
});

test('an ODS cannot open someone elses task page', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->for($workspace)->create();

    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);
    $other = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.person', $other))
        ->assertForbidden();
});

test('the people roster is closed to someone who can only see themselves', function () {
    $workspace = Workspace::factory()->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.people'))
        ->assertForbidden();
});

test('a leader placed in a unit opens the roster and the division page', function () {
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->for($workspace)->create();

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Bod3)
        ->create();

    $session = ['workspace_id' => $workspace->id];

    $this->actingAs($viewer->user)->withSession($session)
        ->get(route('monitoring.people'))->assertOk();

    $this->actingAs($viewer->user)->withSession($session)
        ->get(route('monitoring.divisions'))->assertOk();
});

test('division monitoring is closed to an ODS', function () {
    $workspace = Workspace::factory()->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.divisions'))
        ->assertForbidden();
});

test('a super admin looking into a workspace lands on the roster, not a personal page', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertRedirect(route('monitoring.people'));
});

test('my own task page keeps edit rights on a project i started myself', function () {
    // The person page loads projects with a narrow column list; if it drops
    // `created_by` the owner silently loses their own project.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->for($workspace)->create();

    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create(['created_by' => $owner->user_id]);
    Task::factory()->for($project)->create(['assignee_id' => $owner->user_id]);

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks.0.can_edit', true)
        );
});
