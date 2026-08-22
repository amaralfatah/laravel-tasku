<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{0: WorkspaceMember, 1: OrgUnit}
 */
function projectWorkspace(WorkspaceRole $role): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->for($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    return [$member, $unit];
}

test('the creator joins the project and can add a task straight away', function () {
    // An Asisten is not a manager, so without joining they could not
    // contribute to the project they had just created.
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('projects.store'), [
            'name' => 'Sistem Panen',
            'org_unit_id' => $unit->id,
        ])
        ->assertRedirect();

    $project = Project::withoutGlobalScopes()->where('name', 'Sistem Panen')->firstOrFail();

    expect($project->members()->whereKey($member->user_id)->exists())->toBeTrue();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.store', $project), ['title' => 'Rancang skema'])
        ->assertRedirect();

    expect(Task::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1);
});

test('the board reports the creator as able to contribute', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/board')
            ->where('can.contribute', true)
        );
});

test('an ODS outside the project cannot add tasks to it', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod4);
    $project = Project::factory()->in($unit)->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.store', $project), ['title' => 'Numpang'])
        ->assertForbidden();
});

test('an ODS cannot create a project', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod4);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('projects.store'), ['name' => 'Punya sendiri', 'org_unit_id' => $unit->id])
        ->assertForbidden();
});
