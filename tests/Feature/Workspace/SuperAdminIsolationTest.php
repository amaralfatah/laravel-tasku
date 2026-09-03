<?php

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * SA-4: a super admin operates the platform and may never read the content of
 * a workspace. They hold no membership anywhere, real or virtual.
 */
function superAdmin(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

test('every workspace page bounces a super admin back to the roster', function (string $route) {
    $workspace = Workspace::factory()->create();

    $this->actingAs(superAdmin())
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route($route))
        ->assertRedirect(route('workspaces.index'));
})->with([
    'projects.index',
    'monitoring.me',
    'monitoring.people',
    'monitoring.divisions',
    'members.index',
]);

test('the org structure is the operators page, not a workspace one', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Owner, 'org_unit_id' => $unit->id]);

    $this->actingAs(superAdmin())->get(route('organization.index'))->assertOk();

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('organization.index'))
        ->assertForbidden();
});

test('a leader cannot shape the master structure', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Owner, 'org_unit_id' => $unit->id]);

    $this->actingAs($owner->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('org-units.store'), ['name' => 'Unit Baru', 'parent_id' => $unit->id])
        ->assertForbidden();
});

test('a super admin cannot reach a single project of a workspace', function () {
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $owner = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Owner, 'org_unit_id' => $unit->id]);
    $project = Project::factory()
        ->for($workspace)
        ->create(['org_unit_id' => $unit->id, 'created_by' => $owner->user_id]);

    $this->actingAs(superAdmin())
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('projects.show', $project))
        ->assertRedirect(route('workspaces.index'));
});

test('the roster carries no workspace identity for a super admin', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(superAdmin())
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace', null)
            ->where('tenancy.membership', null)
            ->where('tenancy.workspaces', [])
            ->where('tenancy.projects', [])
        );
});

test('a super admin cannot switch into a workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('workspace.change', $workspace))
        ->assertForbidden();
});

test('a super admin cannot accept a workspace invitation', function () {
    $workspace = Workspace::factory()->create();
    $admin = superAdmin();

    $invitation = Invitation::factory()->for($workspace)->create(['email' => $admin->email]);

    $this->actingAs($admin)
        ->post(route('invitation.accept', $invitation->token), ['name' => $admin->name])
        ->assertForbidden();

    expect(WorkspaceMember::withoutGlobalScopes()->where('user_id', $admin->id)->exists())->toBeFalse();
});

test('an existing member cannot be promoted to super admin', function () {
    $member = WorkspaceMember::factory()->for(Workspace::factory())->create();

    $this->artisan('tasku:super-admin', ['email' => $member->user->email])
        ->assertFailed();

    expect($member->user->fresh()->is_super_admin)->toBeFalse();
});
