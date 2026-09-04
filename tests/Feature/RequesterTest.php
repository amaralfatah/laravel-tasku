<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Requester;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * A workspace with one member at the given role, placed on its root unit.
 *
 * @return array{0: WorkspaceMember, 1: OrgUnit}
 */
function requesterWorkspace(WorkspaceRole $role): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    return [$member, $unit];
}

test('a leader adds a requester and it turns up on the picker', function () {
    [$member, $unit] = requesterWorkspace(WorkspaceRole::Manager);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('requesters.store'), [
            'name' => 'Budi Santoso',
            'organization' => 'Divisi Keuangan',
        ])
        ->assertRedirect();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requesters.0.name', 'Budi Santoso')
            ->where('requesters.0.organization', 'Divisi Keuangan')
            ->etc()
        );
});

test('a member may pick a requester but never add one', function () {
    // The whole point of the managed list: everyone chooses from it, only a
    // leader writes to it. A Member typing a second "Budi" is what free text
    // would have allowed.
    [$leader, $unit] = requesterWorkspace(WorkspaceRole::Owner);
    $worker = WorkspaceMember::factory()
        ->for($leader->workspace)
        ->create(['role' => WorkspaceRole::Member, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($worker->user_id);

    $requester = Requester::factory()
        ->for($leader->workspace)
        ->create(['name' => 'Budi Santoso']);

    $this->actingAs($worker->user)
        ->withSession(['workspace_id' => $worker->workspace_id])
        ->post(route('requesters.store'), ['name' => 'Siti Aminah'])
        ->assertForbidden();

    $this->actingAs($worker->user)
        ->withSession(['workspace_id' => $worker->workspace_id])
        ->get(route('requesters.index'))
        ->assertForbidden();

    $this->actingAs($worker->user)
        ->withSession(['workspace_id' => $worker->workspace_id])
        ->post(route('tasks.store', $project), [
            'title' => 'Laporan panen',
            'requester_id' => $requester->id,
        ])
        ->assertRedirect();

    expect(Task::withoutGlobalScopes()->sole()->requester_id)->toBe($requester->id);
});

test('a name already on the list is refused whatever its casing and spacing', function () {
    [$member] = requesterWorkspace(WorkspaceRole::Owner);
    Requester::factory()->for($member->workspace)->create(['name' => 'Budi Santoso']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('requesters.store'), ['name' => '  budi   santoso '])
        ->assertSessionHasErrors('name');

    expect(Requester::withoutGlobalScopes()->count())->toBe(1);
});

test('a requester of another workspace may not be attached to a task', function () {
    // 7.2 rule 5: an id from the browser stops at the tenant boundary.
    [$member, $unit] = requesterWorkspace(WorkspaceRole::Owner);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $outsider = Requester::factory()->create(['name' => 'Orang Lain']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.store', $project), [
            'title' => 'Laporan panen',
            'requester_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('requester_id');
});

test('a deactivated requester leaves the picker but stays on its tasks', function () {
    [$member, $unit] = requesterWorkspace(WorkspaceRole::Owner);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $requester = Requester::factory()
        ->for($member->workspace)
        ->create(['name' => 'Budi Santoso']);

    $task = Task::factory()->for($project)->create(['requester_id' => $requester->id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('requesters.update', $requester), ['is_active' => false])
        ->assertRedirect();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requesters', [])
            ->where('tasks.0.requester.name', 'Budi Santoso')
            ->etc()
        );

    // And it may not be handed out again while it is retired.
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $task), ['requester_id' => $requester->id])
        ->assertSessionHasErrors('requester_id');
});

test('a requester named by a task is kept, an unused one is deleted', function () {
    [$member, $unit] = requesterWorkspace(WorkspaceRole::Owner);
    $project = Project::factory()->in($unit)->create();

    $used = Requester::factory()->for($member->workspace)->create();
    $unused = Requester::factory()->for($member->workspace)->create();

    Task::factory()->for($project)->create(['requester_id' => $used->id]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->delete(route('requesters.destroy', $used))
        ->assertSessionHasErrors('requester');

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->delete(route('requesters.destroy', $unused))
        ->assertRedirect();

    expect(Requester::withoutGlobalScopes()->whereKey($used->id)->exists())->toBeTrue()
        ->and(Requester::withoutGlobalScopes()->whereKey($unused->id)->exists())->toBeFalse();
});

test('a viewer neither manages the list nor reaches its writes', function () {
    [$leader, $unit] = requesterWorkspace(WorkspaceRole::Owner);
    $viewer = WorkspaceMember::factory()
        ->for($leader->workspace)
        ->create(['role' => WorkspaceRole::Viewer, 'org_unit_id' => $unit->id]);

    $requester = Requester::factory()->for($leader->workspace)->create();

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $viewer->workspace_id])
        ->get(route('requesters.index'))
        ->assertForbidden();

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $viewer->workspace_id])
        ->patch(route('requesters.update', $requester), ['name' => 'Diubah'])
        ->assertForbidden();
});
