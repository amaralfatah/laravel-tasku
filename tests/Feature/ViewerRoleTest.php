<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * A Viewer is the commissioner, the auditor, the client: they read the whole
 * slice they were given and change nothing in it.
 */

/**
 * @return array{workspace: Workspace, marketing: OrgUnit, engineering: OrgUnit}
 */
function viewerWorkspace(): array
{
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->rootOf($workspace)->create(['name' => 'Divisi']);
    $marketing = OrgUnit::factory()->childOf($root)->create(['name' => 'Marketing']);
    $engineering = OrgUnit::factory()->childOf($root)->create(['name' => 'Engineering']);

    return compact('workspace', 'marketing', 'engineering');
}

test('a viewer with no unit reads every project in the workspace', function () {
    ['workspace' => $workspace, 'marketing' => $marketing, 'engineering' => $engineering] = viewerWorkspace();

    Project::factory()->in($marketing)->create(['name' => 'Kampanye panen']);
    Project::factory()->in($engineering)->create(['name' => 'API gateway']);

    $viewer = WorkspaceMember::factory()->for($workspace)->viewer()->create();

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('projects', 2)
            ->where('tenancy.membership.can_write', false)
            ->where('tenancy.membership.can_monitor', true)
            ->where('tenancy.membership.can_manage', false)
        );
});

test('a viewer placed in a unit reads only that branch', function () {
    ['workspace' => $workspace, 'marketing' => $marketing, 'engineering' => $engineering] = viewerWorkspace();

    Project::factory()->in($marketing)->create(['name' => 'Kampanye panen']);
    $mine = Project::factory()->in($engineering)->create(['name' => 'API gateway']);

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->viewer()
        ->in($engineering)
        ->create();

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('projects', 1)
            ->where('projects.0.name', $mine->name)
        );
});

test('a viewer may not start a project', function () {
    ['workspace' => $workspace, 'engineering' => $engineering] = viewerWorkspace();

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->viewer()
        ->in($engineering)
        ->create();

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('projects.store'), ['name' => 'Audit internal'])
        ->assertForbidden();

    expect(Project::query()->count())->toBe(0);
});

test('a viewer on a project may not touch its tasks', function () {
    ['workspace' => $workspace, 'engineering' => $engineering] = viewerWorkspace();

    $project = Project::factory()->in($engineering)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Audit endpoint']);

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->viewer()
        ->in($engineering)
        ->create();

    // Being on the member list is not a way around it: the tier decides.
    $project->members()->attach($viewer->user_id);

    $this->actingAs($viewer->user)->withSession(['workspace_id' => $workspace->id]);

    $this->post(route('tasks.store', $project), ['title' => 'Tugas baru'])->assertForbidden();
    $this->patch(route('tasks.update', $task), ['title' => 'Diubah'])->assertForbidden();
    $this->delete(route('tasks.destroy', $task))->assertForbidden();
    $this->post(route('comments.store', $task), ['body' => 'Catatan'])->assertForbidden();

    expect($task->refresh()->title)->toBe('Audit endpoint');
});

test('a viewer reads the task board of a project in their branch', function () {
    ['workspace' => $workspace, 'engineering' => $engineering] = viewerWorkspace();

    $project = Project::factory()->in($engineering)->create();
    Task::factory()->for($project)->create(['title' => 'Audit endpoint']);

    $viewer = WorkspaceMember::factory()
        ->for($workspace)
        ->viewer()
        ->in($engineering)
        ->create();

    $this->actingAs($viewer->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.show', $project))
        ->assertOk();
});

test('a viewer opens the reporting pages but never the roster controls', function () {
    ['workspace' => $workspace] = viewerWorkspace();

    $viewer = WorkspaceMember::factory()->for($workspace)->viewer()->create();

    $this->actingAs($viewer->user)->withSession(['workspace_id' => $workspace->id]);

    $this->get(route('monitoring.divisions'))->assertOk();
    $this->get(route('monitoring.people'))->assertOk();
    $this->get(route('members.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));
});

test('a viewer may not hand out roles or invite anyone', function () {
    ['workspace' => $workspace, 'engineering' => $engineering] = viewerWorkspace();

    $viewer = WorkspaceMember::factory()->for($workspace)->viewer()->create();
    $staff = WorkspaceMember::factory()->for($workspace)->in($engineering)->create();

    $this->actingAs($viewer->user)->withSession(['workspace_id' => $workspace->id]);

    // A Viewer may hand out no role at all, so the field itself refuses.
    $this->patch(route('members.update', $staff), ['role' => WorkspaceRole::Owner->value])
        ->assertSessionHasErrors('role');
    $this->post(route('invitations.store'), ['email' => 'auditor@example.test', 'role' => WorkspaceRole::Member->value])
        ->assertForbidden();

    expect($staff->refresh()->role)->toBe(WorkspaceRole::Member);
});

test('a job title is a label the workspace edits, and grants nothing', function () {
    ['workspace' => $workspace, 'engineering' => $engineering] = viewerWorkspace();

    $owner = WorkspaceMember::factory()->for($workspace)->owner()->create();
    $staff = WorkspaceMember::factory()
        ->for($workspace)
        ->in($engineering)
        ->create(['title' => null]);

    // With nothing typed, the tier's own name stands in.
    expect($staff->positionTitle())->toBe('Anggota');

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('members.update', $staff), ['title' => 'Asisten Cyber Security'])
        ->assertRedirect();

    $staff->refresh();

    expect($staff->title)->toBe('Asisten Cyber Security')
        ->and($staff->role)->toBe(WorkspaceRole::Member)
        ->and($staff->managesTeam())->toBeFalse();
});
