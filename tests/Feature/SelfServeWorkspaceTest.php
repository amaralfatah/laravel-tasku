<?php

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceScale;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * The self-serve path: someone signs up, names a workspace and is working.
 * No operator provisions anything, and the result is an ordinary workspace —
 * the same schema a holding uses, just with one person in it.
 */
test('a new account starts its own workspace and lands inside it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspace.none'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canStart', true));

    $this->actingAs($user)
        ->post(route('workspace.start'), ['name' => 'Studio Rekayasa'])
        ->assertRedirect(route('projects.index'));

    $workspace = Workspace::query()->where('name', 'Studio Rekayasa')->firstOrFail();
    $member = WorkspaceMember::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
    $root = OrgUnit::query()->whereKey($workspace->root_org_unit_id)->firstOrFail();

    expect($member->role)->toBe(WorkspaceRole::Owner)
        ->and($member->org_unit_id)->toBe($root->id)
        ->and($root->name)->toBe('Studio Rekayasa')
        // Drawn by the customer, so no import will ever overwrite it.
        ->and($root->external_id)->toBeNull()
        ->and(WorkspaceScale::of($workspace))->toBe(WorkspaceScale::Solo);
});

test('a solo workspace is not shown the organisation it does not have', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspace.start'), ['name' => 'Freelance Saya']);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace.scale', 'solo')
            ->where('tenancy.workspace.is_holding', false)
            ->where('tenancy.membership.role', 'owner')
        );
});

test('the owner grows their own structure from the node they were given', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspace.start'), ['name' => 'Tim Kecil']);

    $workspace = Workspace::query()->where('name', 'Tim Kecil')->firstOrFail();
    $root = OrgUnit::query()->whereKey($workspace->root_org_unit_id)->firstOrFail();

    $this->actingAs($user)
        ->post(route('org-units.store'), ['name' => 'Produk', 'parent_id' => $root->id, 'type' => 'division'])
        ->assertRedirect();

    $unit = OrgUnit::query()->where('name', 'Produk')->firstOrFail();

    expect($unit->parent_id)->toBe($root->id)
        ->and($unit->path)->toStartWith($root->path)
        ->and(WorkspaceScale::of($workspace->refresh()))->toBe(WorkspaceScale::Company);
});

test('someone who already belongs somewhere cannot start a second workspace', function () {
    $workspace = Workspace::factory()->create();
    OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($member->user)
        ->post(route('workspace.start'), ['name' => 'Diam-diam'])
        ->assertForbidden();

    expect(Workspace::query()->where('name', 'Diam-diam')->exists())->toBeFalse();
});

test('a deactivated workspace is not a way to start a fresh one', function () {
    $workspace = Workspace::factory()->inactive()->create();
    OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($member->user)
        ->get(route('workspace.none'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canStart', false));

    $this->actingAs($member->user)
        ->post(route('workspace.start'), ['name' => 'Menghindar'])
        ->assertForbidden();
});

test('a super admin never starts a workspace to work in', function () {
    $operator = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($operator)
        ->post(route('workspace.start'), ['name' => 'Milik Operator'])
        ->assertForbidden();
});
