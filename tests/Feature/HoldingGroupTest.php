<?php

use App\Enums\WorkspaceScale;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\WorkspaceAccess;
use Inertia\Testing\AssertableInertia;

/**
 * A holding is a workspace with workspaces under it. Its group-level roles
 * reach down into every company; nothing else crosses the boundary.
 */

/**
 * @return array{holding: Workspace, first: Workspace, second: Workspace}
 */
function group(): array
{
    $holding = Workspace::factory()->create(['name' => 'Induk Grup']);
    $first = Workspace::factory()->under($holding)->create(['name' => 'Anak Usaha A']);
    $second = Workspace::factory()->under($holding)->create(['name' => 'Anak Usaha B']);

    foreach ([$holding, $first, $second] as $workspace) {
        OrgUnit::factory()->rootOf($workspace)->create();
    }

    return compact('holding', 'first', 'second');
}

test('a holding owner reaches every company in the group', function () {
    ['holding' => $holding, 'first' => $first, 'second' => $second] = group();

    $director = WorkspaceMember::factory()->for($holding)->owner()->create();

    $memberships = app(WorkspaceAccess::class)->memberships($director->user);

    expect($memberships->keys()->sort()->values()->all())
        ->toBe(collect([$holding->id, $first->id, $second->id])->sort()->values()->all())
        ->and($memberships->get($first->id)->projected)->toBeTrue()
        ->and($memberships->get($holding->id)->projected)->toBeFalse();
});

test('a holding manager stays inside the holding', function () {
    ['holding' => $holding, 'first' => $first] = group();

    $unit = OrgUnit::query()->where('id', $holding->root_org_unit_id)->firstOrFail();
    $manager = WorkspaceMember::factory()->for($holding)->leading($unit)->create();

    $memberships = app(WorkspaceAccess::class)->memberships($manager->user);

    expect($memberships->has($holding->id))->toBeTrue()
        ->and($memberships->has($first->id))->toBeFalse();

    $this->actingAs($manager->user)
        ->post(route('workspace.change', $first))
        ->assertForbidden();
});

test('a stored membership beats the one projected from the holding', function () {
    ['holding' => $holding, 'first' => $first] = group();

    $unit = OrgUnit::query()->where('id', $first->root_org_unit_id)->firstOrFail();

    $director = WorkspaceMember::factory()->for($holding)->owner()->create();
    WorkspaceMember::factory()
        ->for($first)
        ->leading($unit)
        ->create(['user_id' => $director->user_id]);

    $membership = app(WorkspaceAccess::class)->memberships($director->user)->get($first->id);

    expect($membership->projected)->toBeFalse()
        ->and($membership->hasFullScope())->toBeFalse();
});

test('the consolidated page reports every company once', function () {
    ['holding' => $holding, 'first' => $first, 'second' => $second] = group();

    $unit = OrgUnit::query()->where('id', $first->root_org_unit_id)->firstOrFail();
    $project = Project::factory()->for($first)->in($unit)->create();

    Task::factory()->for($first)->for($project)->count(3)->create();
    Task::factory()->for($first)->for($project)->done()->create();

    $director = WorkspaceMember::factory()->for($holding)->owner()->create();

    $this->actingAs($director->user)
        ->withSession(['workspace_id' => $holding->id])
        ->get(route('group.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('group/index')
            ->has('companies', 2)
            ->where('companies.0.name', $first->name)
            ->where('companies.0.tasks', 4)
            ->where('companies.0.done', 1)
            ->where('companies.0.progress', 25)
            ->where('companies.1.name', $second->name)
            ->where('companies.1.tasks', 0)
            ->where('totals.companies', 2)
            ->where('totals.tasks', 4)
        );
});

test('a group viewer reads the consolidation and enters a company read-only', function () {
    ['holding' => $holding, 'first' => $first] = group();

    $auditor = WorkspaceMember::factory()->for($holding)->viewer()->create();

    $this->actingAs($auditor->user)->withSession(['workspace_id' => $holding->id]);

    $this->get(route('group.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.write', false));

    // Reading a subsidiary is allowed — writing in it is what the tier stops.
    $this->post(route('group.enter', $first))->assertRedirect();

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace.id', $first->id)
            ->where('tenancy.membership.can_write', false)
            ->where('tenancy.membership.via_group', true)
        );
});

test('a company without a group never sees the consolidated page', function () {
    $workspace = Workspace::factory()->create();
    OrgUnit::factory()->rootOf($workspace)->create();

    $owner = WorkspaceMember::factory()->for($workspace)->owner()->create();

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('group.index'))
        ->assertForbidden();
});

test('standing inside a subsidiary shows that subsidiary alone', function () {
    ['holding' => $holding, 'first' => $first, 'second' => $second] = group();

    $unit = OrgUnit::query()->where('id', $second->root_org_unit_id)->firstOrFail();
    $hidden = Project::factory()->for($second)->in($unit)->create(['name' => 'Rahasia B']);

    $director = WorkspaceMember::factory()->for($holding)->owner()->create();

    $this->actingAs($director->user)
        ->withSession(['workspace_id' => $first->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace.id', $first->id)
            ->has('projects', 0)
        );

    expect(Project::withoutGlobalScopes()->whereKey($hidden->id)->value('workspace_id'))
        ->toBe($second->id);
});

test('an inactive holding stops opening doors downwards', function () {
    ['holding' => $holding, 'first' => $first] = group();

    $director = WorkspaceMember::factory()->for($holding)->owner()->create();
    $holding->update(['is_active' => false]);

    expect(app(WorkspaceAccess::class)->memberships($director->user->refresh())->keys()->all())
        ->toBe([]);

    $this->actingAs($director->user)
        ->post(route('workspace.change', $first))
        ->assertForbidden();
});

test('a workspace may not be placed under its own company', function () {
    ['holding' => $holding, 'first' => $first] = group();

    $operator = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($operator)
        ->patch(route('workspaces.update', $holding), ['parent_id' => $first->id])
        ->assertSessionHasErrors('parent_id');

    expect($holding->refresh()->parent_id)->toBeNull();
});

test('scale follows the data, from one person up to a group', function () {
    $solo = Workspace::factory()->create();
    $root = OrgUnit::factory()->rootOf($solo)->create();
    WorkspaceMember::factory()->for($solo)->owner()->in($root)->create();

    expect(WorkspaceScale::of($solo->refresh()))->toBe(WorkspaceScale::Solo);

    WorkspaceMember::factory()->for($solo)->in($root)->create();

    expect(WorkspaceScale::of($solo->refresh()))->toBe(WorkspaceScale::Team);

    OrgUnit::factory()->childOf($root)->create();

    expect(WorkspaceScale::of($solo->refresh()))->toBe(WorkspaceScale::Company);

    Workspace::factory()->under($solo)->create();

    expect(WorkspaceScale::of($solo->refresh()))->toBe(WorkspaceScale::Holding);
});
