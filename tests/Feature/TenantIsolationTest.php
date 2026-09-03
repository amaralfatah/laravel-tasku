<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * R-1: the highest risk in a single database multi-tenant app is one company
 * reaching another's rows by changing an id in the URL or in a form.
 */

/**
 * @return array{0: WorkspaceMember, 1: OrgUnit}
 */
function workspaceWith(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    return [$member, $unit];
}

test('a project of another workspace is not visible', function () {
    [$member] = workspaceWith();
    [, $otherUnit] = workspaceWith();
    $foreign = Project::factory()->in($otherUnit)->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $foreign))
        ->assertForbidden();
});

test('a task of another workspace cannot be updated', function () {
    [$member] = workspaceWith();
    [, $otherUnit] = workspaceWith();
    $foreignTask = Task::factory()->for(Project::factory()->in($otherUnit))->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $foreignTask), ['title' => 'Diambil alih'])
        ->assertForbidden();

    expect($foreignTask->fresh()->title)->not->toBe('Diambil alih');
});

test('a project cannot be attached to another workspace org unit', function () {
    [$member] = workspaceWith();
    [, $otherUnit] = workspaceWith();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('projects.store'), [
            'name' => 'Project selundupan',
            'org_unit_id' => $otherUnit->id,
        ])
        ->assertSessionHasErrors('org_unit_id');

    expect(Project::withoutGlobalScopes()->where('name', 'Project selundupan')->exists())->toBeFalse();
});

test('a sibling branch of the master tree stays out of the workspace', function () {
    // Units are shared master data, so the boundary is the subtree a workspace
    // was placed on, not a foreign key.
    $holding = OrgUnit::factory()->create(['name' => 'Holding']);
    $mine = OrgUnit::factory()->childOf($holding)->create(['name' => 'PalmCo']);
    $theirs = OrgUnit::factory()->childOf($holding)->create(['name' => 'SupportingCo']);

    $workspace = Workspace::factory()->create(['root_org_unit_id' => $mine->id]);
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Owner, 'org_unit_id' => $mine->id]);

    expect($member->covers($mine->id))->toBeTrue()
        ->and($member->covers($theirs->id))->toBeFalse()
        ->and($member->covers($holding->id))->toBeFalse();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Co']))
        ->assertOk()
        ->assertJsonCount(1, 'units')
        ->assertJsonPath('units.0.name', 'PalmCo');
});

test('a workspace with no place in the tree has no units at all', function () {
    OrgUnit::factory()->create(['name' => 'Holding']);

    $workspace = Workspace::factory()->create(['root_org_unit_id' => null]);
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Owner]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->getJson(route('org-units.search', ['q' => 'Holding']))
        ->assertOk()
        ->assertJsonCount(0, 'units');
});

test('a member cannot be moved into another workspace org unit', function () {
    [$manager] = workspaceWith();
    [, $otherUnit] = workspaceWith();
    $target = WorkspaceMember::factory()
        ->for($manager->workspace)
        ->create(['role' => WorkspaceRole::Member]);

    $this->actingAs($manager->user)
        ->withSession(['workspace_id' => $manager->workspace_id])
        ->patch(route('members.update', $target), ['org_unit_id' => $otherUnit->id])
        ->assertSessionHasErrors('org_unit_id');

    expect($target->fresh()->org_unit_id)->toBeNull();
});

test('a task cannot be assigned to someone outside the project', function () {
    [$member, $unit] = workspaceWith();
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $outsider = WorkspaceMember::factory()->for($member->workspace)->create();
    $task = Task::factory()->for($project)->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('tasks.update', $task), ['assignee_id' => $outsider->user_id])
        ->assertSessionHasErrors('assignee_id');
});

test('a revoked membership loses access on the very next request', function () {
    [$member, $unit] = workspaceWith(WorkspaceRole::Member);
    Project::factory()->in($unit)->create();

    $session = ['workspace_id' => $member->workspace_id];

    $this->actingAs($member->user)
        ->withSession($session)
        ->get(route('projects.index'))
        ->assertOk();

    $member->delete();

    $this->actingAs($member->user)
        ->withSession($session)
        ->get(route('projects.index'))
        ->assertRedirect(route('workspace.none'));
});

test('a suspended workspace locks its members out', function () {
    [$member] = workspaceWith();
    $member->workspace->update(['is_active' => false]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.index'))
        ->assertRedirect(route('workspace.none'));
});
