<?php

use App\Enums\TaskStatus;
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
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);
    Task::factory()->for($project)->create(['assignee_id' => $member->user_id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/focus')
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
    $root = OrgUnit::factory()->rootOf($workspace)->create();
    $child = OrgUnit::factory()->childOf($root)->create();

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Manager)
        ->create();

    $worker = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $child->id]);

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
    $unit = OrgUnit::factory()->rootOf($workspace)->create();

    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);
    $other = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.person', $other))
        ->assertForbidden();
});

test('the people roster is closed to someone who can only see themselves', function () {
    $workspace = Workspace::factory()->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.people'))
        ->assertForbidden();
});

test('a leader placed in a unit opens the roster and the division page', function () {
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->rootOf($workspace)->create();

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($root, WorkspaceRole::Manager)
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
        ->create(['role' => WorkspaceRole::Member]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.divisions'))
        ->assertForbidden();
});

test('a super admin cannot open a workspace page at all', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertRedirect(route('workspaces.index'));
});

test('my own task page keeps edit rights on a project i started myself', function () {
    // The person page loads projects with a narrow column list; if it drops
    // `created_by` the owner silently loses their own project.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();

    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

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

test('a member who leads nobody is not sent from their own timeline into a roster they may not open', function () {
    // The timeline is reachable by everyone for themselves, but the roster
    // above it is not — so the page must not offer it as the way back.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $this->actingAs($member->user)->withSession(['workspace_id' => $workspace->id]);

    $this->get(route('monitoring.person', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/person')
            ->where('isSelf', true)
            ->where('tenancy.membership.can_monitor', false)
        );

    $this->get(route('monitoring.people'))->assertForbidden();
});

test('the landing page carries only recent finished work and counts the rest', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    Task::factory()->for($project)->create([
        'assignee_id' => $member->user_id,
        'status' => TaskStatus::Done,
        'completed_at' => now()->subDays(3),
    ]);

    $old = Task::factory()->count(2)->for($project)->create([
        'assignee_id' => $member->user_id,
        'status' => TaskStatus::Done,
    ]);

    // The observer stamps `completed_at` itself the moment a status turns
    // done, so the age has to be written past it.
    Task::whereKey($old->modelKeys())->update(['completed_at' => now()->subDays(90)]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/focus')
            ->where('olderDone', 2)
            ->where('doneWindowDays', 14)
            ->has('tasks.0.tasks', 1)
        );

    // The timeline is the full record, so nothing is trimmed there.
    $this->get(route('monitoring.person', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/person')
            ->has('tasks.0.tasks', 3)
        );
});

test('the sidebar can address the viewer their own timeline', function () {
    // The gantt gave up its tab beside the agenda for a sidebar row, and that
    // row is built from the member id. It sits outside the "Monitoring" group
    // on purpose: someone who leads nobody is refused the roster and would
    // never see it there, but their own timeline is theirs to open.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.membership.id', $member->id)
            ->where('tenancy.membership.can_monitor', false)
        );

    $this->get(route('monitoring.person', $member))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/person')
        );
});

test('the agenda filter rides in the query string without narrowing the page', function () {
    // The segmented control above the agenda filters in the browser, and the
    // count beside each of its labels is counted over every open task. So the
    // server has to keep sending all of them whatever `signal` says — filter
    // here and the numbers would describe a list nobody can see.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    Task::factory()->for($project)->create([
        'assignee_id' => $member->user_id,
        'due_date' => now()->subWeek(),
    ]);

    Task::factory()->for($project)->create([
        'assignee_id' => $member->user_id,
        'due_date' => now()->addMonth(),
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('monitoring.me', ['signal' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monitoring/focus')
            ->has('tasks.0.tasks', 2)
        );
});
