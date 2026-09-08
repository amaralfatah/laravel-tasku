<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * The operator placing people in workspaces, and handing a workspace to a new
 * Owner (SA-6).
 *
 * The rule that shapes all of it: a workspace must always keep at least one
 * Owner, so replacing one is appoint-then-demote, never the other way round.
 */

/**
 * @return array{operator: User, workspace: Workspace, root: OrgUnit, cabang: OrgUnit}
 */
function operatorWorkspace(): array
{
    $operator = User::factory()->superAdmin()->create();
    $workspace = Workspace::factory()->create();
    $root = OrgUnit::factory()->rootOf($workspace)->create(['name' => 'Divisi']);
    $cabang = OrgUnit::factory()->childOf($root)->create(['name' => 'Cabang']);

    return compact('operator', 'workspace', 'root', 'cabang');
}

test('the operator places an account in a workspace', function () {
    ['operator' => $operator, 'workspace' => $workspace, 'cabang' => $cabang] = operatorWorkspace();
    $user = User::factory()->create();

    $this->actingAs($operator)
        ->post(route('users.memberships.store', $user), [
            'workspace_id' => $workspace->id,
            'role' => WorkspaceRole::Manager->value,
            'title' => 'Kepala Sub Divisi',
            'org_unit_id' => $cabang->id,
        ])
        ->assertRedirect();

    $member = WorkspaceMember::withoutGlobalScopes()
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($member->workspace_id)->toBe($workspace->id)
        ->and($member->role)->toBe(WorkspaceRole::Manager)
        ->and($member->org_unit_id)->toBe($cabang->id)
        ->and($member->title)->toBe('Kepala Sub Divisi');
});

test('a unit outside the workspace subtree is refused', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    $user = User::factory()->create();

    $elsewhere = Workspace::factory()->create();
    $strayUnit = OrgUnit::factory()->rootOf($elsewhere)->create(['name' => 'Perusahaan Lain']);

    $this->actingAs($operator)
        ->post(route('users.memberships.store', $user), [
            'workspace_id' => $workspace->id,
            'role' => WorkspaceRole::Member->value,
            'org_unit_id' => $strayUnit->id,
        ])
        ->assertSessionHasErrors('org_unit_id');
});

test('a super admin cannot be given a membership', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    $other = User::factory()->superAdmin()->create();

    $this->actingAs($operator)
        ->post(route('users.memberships.store', $other), [
            'workspace_id' => $workspace->id,
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertSessionHasErrors('user_id');

    expect(WorkspaceMember::withoutGlobalScopes()->where('user_id', $other->id)->exists())->toBeFalse();
});

test('the same account cannot be added to one workspace twice', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    $member = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($operator)
        ->post(route('users.memberships.store', $member->user), [
            'workspace_id' => $workspace->id,
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertSessionHasErrors('workspace_id');
});

test('the operator hands a workspace to a new owner, then demotes the old one', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();

    $incumbent = WorkspaceMember::factory()->for($workspace)->owner()->create();
    $successor = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($operator)
        ->patch(
            route('users.memberships.update', [
                'user' => $successor->user_id,
                'member' => $successor->id,
            ]),
            ['role' => WorkspaceRole::Owner->value],
        )
        ->assertRedirect();

    $this->actingAs($operator)
        ->patch(
            route('users.memberships.update', [
                'user' => $incumbent->user_id,
                'member' => $incumbent->id,
            ]),
            ['role' => WorkspaceRole::Member->value],
        )
        ->assertRedirect();

    expect($successor->refresh()->role)->toBe(WorkspaceRole::Owner)
        ->and($incumbent->refresh()->role)->toBe(WorkspaceRole::Member);
});

test('the last owner cannot be demoted', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    $owner = WorkspaceMember::factory()->for($workspace)->owner()->create();

    $this->actingAs($operator)
        ->patch(
            route('users.memberships.update', [
                'user' => $owner->user_id,
                'member' => $owner->id,
            ]),
            ['role' => WorkspaceRole::Member->value],
        )
        ->assertSessionHasErrors('role');

    expect($owner->refresh()->role)->toBe(WorkspaceRole::Owner);
});

test('the last owner cannot be removed from the workspace', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    $owner = WorkspaceMember::factory()->for($workspace)->owner()->create();

    $this->actingAs($operator)
        ->delete(route('users.memberships.destroy', [
            'user' => $owner->user_id,
            'member' => $owner->id,
        ]))
        ->assertSessionHasErrors('membership');

    expect(WorkspaceMember::withoutGlobalScopes()->whereKey($owner->id)->exists())->toBeTrue();
});

test('the operator removes a member once the workspace still has an owner', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    WorkspaceMember::factory()->for($workspace)->owner()->create();
    $leaving = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($operator)
        ->delete(route('users.memberships.destroy', [
            'user' => $leaving->user_id,
            'member' => $leaving->id,
        ]))
        ->assertRedirect();

    expect(WorkspaceMember::withoutGlobalScopes()->whereKey($leaving->id)->exists())->toBeFalse();
});

test('a membership belonging to somebody else is not reachable through another account', function () {
    ['operator' => $operator, 'workspace' => $workspace] = operatorWorkspace();
    WorkspaceMember::factory()->for($workspace)->owner()->create();
    $target = WorkspaceMember::factory()->for($workspace)->create();
    $bystander = User::factory()->create();

    $this->actingAs($operator)
        ->patch(
            route('users.memberships.update', [
                'user' => $bystander->id,
                'member' => $target->id,
            ]),
            ['role' => WorkspaceRole::Owner->value],
        )
        ->assertNotFound();

    expect($target->refresh()->role)->toBe(WorkspaceRole::Member);
});

test('a workspace owner cannot reach the operator membership routes', function () {
    ['workspace' => $workspace] = operatorWorkspace();
    $owner = WorkspaceMember::factory()->for($workspace)->owner()->create();
    $target = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->patch(
            route('users.memberships.update', [
                'user' => $target->user_id,
                'member' => $target->id,
            ]),
            ['role' => WorkspaceRole::Owner->value],
        )
        ->assertForbidden();
});
