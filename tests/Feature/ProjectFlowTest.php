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
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
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

test('an ODS starts a project in their own unit and runs it', function () {
    // Team-managed: anyone with a place in the tree may open a project, and
    // whoever opened it administers that one project.
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod4);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('projects.store'), ['name' => 'Punya sendiri', 'org_unit_id' => $unit->id])
        ->assertRedirect();

    $project = Project::withoutGlobalScopes()->where('name', 'Punya sendiri')->firstOrFail();

    expect($project->org_unit_id)->toBe($unit->id)
        ->and($project->created_by)->toBe($member->user_id)
        ->and($project->members()->whereKey($member->user_id)->exists())->toBeTrue();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('projects.update', $project), ['name' => 'Ganti nama'])
        ->assertRedirect();

    expect($project->refresh()->name)->toBe('Ganti nama');
});

test('an ODS cannot start a project outside their own unit', function () {
    [$member] = projectWorkspace(WorkspaceRole::Bod4);
    $elsewhere = OrgUnit::factory()->rootOf($member->workspace)->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('projects.store'), ['name' => 'Nyasar', 'org_unit_id' => $elsewhere->id])
        ->assertRedirect();

    // The unit is taken from where they sit, never from the request.
    expect(Project::withoutGlobalScopes()->where('name', 'Nyasar')->value('org_unit_id'))
        ->toBe($member->org_unit_id);
});

test('someone with no unit at all cannot create a project', function () {
    $workspace = Workspace::factory()->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => null]);

    $unit = OrgUnit::factory()->rootOf($workspace)->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('projects.store'), ['name' => 'Tanpa unit', 'org_unit_id' => $unit->id])
        ->assertForbidden();
});

test('an ODS cannot touch a project someone else started', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod4);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->patch(route('projects.update', $project), ['name' => 'Bajak'])
        ->assertForbidden();
});

test('dropping a card on the board moves it to the given status and sibling index', function () {
    // The board sends one flat index across every column, because root tasks
    // share a single sibling order (BRD-3).
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $tasks = collect(['Satu', 'Dua', 'Tiga'])->map(fn (string $title) => Task::factory()
        ->for($project)
        ->create(['workspace_id' => $project->workspace_id, 'title' => $title]));

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.move', $tasks[2]), ['status' => 'in_progress', 'position' => 0])
        ->assertRedirect();

    $ordered = Task::withoutGlobalScopes()
        ->where('project_id', $project->id)
        ->orderBy('position')
        ->pluck('title')
        ->all();

    expect($ordered)->toBe(['Tiga', 'Satu', 'Dua']);
    expect($tasks[2]->refresh()->status->value)->toBe('in_progress');
});

test('creating from a board column lands the task in that column', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);
    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.store', $project), [
            'title' => 'Langsung dikerjakan',
            'status' => 'in_progress',
        ])
        ->assertRedirect();

    $task = Task::withoutGlobalScopes()->where('title', 'Langsung dikerjakan')->firstOrFail();

    expect($task->status->value)->toBe('in_progress');
});

test('the leader above still runs a project an ODS started', function () {
    // Team-managed does not carve the project out of the org tree: whoever
    // covers the unit keeps their authority over it.
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $child = OrgUnit::factory()->childOf($unit)->create();

    $asisten = WorkspaceMember::factory()
        ->for($workspace)
        ->leading($unit, WorkspaceRole::Bod3)
        ->create();

    $ods = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $child->id]);

    $project = Project::factory()->in($child)->create(['created_by' => $ods->user_id]);

    $this->actingAs($asisten->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(route('projects.update', $project), ['name' => 'Diarahkan ulang'])
        ->assertRedirect();

    expect($project->refresh()->name)->toBe('Diarahkan ulang');
});

test('whoever started a project may delete a task they did not write', function () {
    [$owner, $unit] = projectWorkspace(WorkspaceRole::Bod4);
    $helper = WorkspaceMember::factory()
        ->for($owner->workspace)
        ->create(['role' => WorkspaceRole::Bod4, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create(['created_by' => $owner->user_id]);
    $project->members()->attach([$owner->user_id, $helper->user_id]);

    $ownersTask = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'created_by' => $owner->user_id,
    ]);

    $helpersTask = Task::factory()->for($project)->create([
        'workspace_id' => $project->workspace_id,
        'created_by' => $helper->user_id,
    ]);

    $session = ['workspace_id' => $owner->workspace_id];

    // The helper is only a member, so someone else's task is not theirs to remove.
    $this->actingAs($helper->user)->withSession($session)
        ->delete(route('tasks.destroy', $ownersTask))
        ->assertForbidden();

    // The owner administers the project, so every task in it is theirs to remove.
    $this->actingAs($owner->user)->withSession($session)
        ->delete(route('tasks.destroy', $helpersTask))
        ->assertRedirect();
});

test('a project left without a key gets one derived from its name', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('projects.store'), [
            'name' => 'Aplikasi Absensi Karyawan',
            'org_unit_id' => $unit->id,
        ])
        ->assertRedirect();

    expect(Project::withoutGlobalScopes()->where('name', 'Aplikasi Absensi Karyawan')->value('key'))
        ->toBe('AAK');
});

test('a key typed by hand is stored upper case and cannot repeat in the workspace', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);
    $session = ['workspace_id' => $member->workspace_id];

    $this->actingAs($member->user)->withSession($session)
        ->post(route('projects.store'), [
            'name' => 'Sistem Panen',
            'key' => 'panen',
            'org_unit_id' => $unit->id,
        ])
        ->assertRedirect();

    expect(Project::withoutGlobalScopes()->where('name', 'Sistem Panen')->value('key'))->toBe('PANEN');

    $this->actingAs($member->user)->withSession($session)
        ->post(route('projects.store'), [
            'name' => 'Panen Lanjutan',
            'key' => 'PANEN',
            'org_unit_id' => $unit->id,
        ])
        ->assertSessionHasErrors('key');
});

test('two workspaces may hold the same project key', function () {
    [, $firstUnit] = projectWorkspace(WorkspaceRole::Bod3);
    [, $secondUnit] = projectWorkspace(WorkspaceRole::Bod3);

    Project::factory()->in($firstUnit)->create(['key' => 'PANEN']);
    Project::factory()->in($secondUnit)->create(['key' => 'PANEN']);

    expect(Project::withoutGlobalScopes()->where('key', 'PANEN')->count())->toBe(2);
});

test('a key already taken makes the generated one fall to a numbered variant', function () {
    [$member, $unit] = projectWorkspace(WorkspaceRole::Bod3);
    $session = ['workspace_id' => $member->workspace_id];

    Project::factory()->in($unit)->create(['key' => 'SP']);

    $this->actingAs($member->user)->withSession($session)
        ->post(route('projects.store'), ['name' => 'Sistem Panen', 'org_unit_id' => $unit->id])
        ->assertRedirect();

    expect(Project::withoutGlobalScopes()->where('name', 'Sistem Panen')->value('key'))->toBe('SP2');
});
