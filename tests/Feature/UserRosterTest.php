<?php

use App\Models\User;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

/**
 * The operator's account roster (SA-5).
 *
 * Everything here runs without tenant context: a super admin never enters a
 * workspace (SA-4), so the pages scope their own queries.
 */
test('a workspace owner cannot reach the account roster', function () {
    $member = WorkspaceMember::factory()->owner()->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('users.index'))
        ->assertForbidden();
});

test('the roster lists every account on the platform', function () {
    $operator = User::factory()->superAdmin()->create();
    WorkspaceMember::factory()->owner()->create();
    WorkspaceMember::factory()->create();

    $this->actingAs($operator)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('users/index')
            // The two members plus the operator's own account.
            ->where('stats.total', 3)
            ->has('users.data', 3)
        );
});

test('search matches on name and on email', function () {
    $operator = User::factory()->superAdmin()->create();
    User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@panen.test']);
    User::factory()->create(['name' => 'Siti Rahayu', 'email' => 'siti@panen.test']);

    $this->actingAs($operator)
        ->get(route('users.index', ['search' => 'budi']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'budi@panen.test')
        );

    $this->actingAs($operator)
        ->get(route('users.index', ['search' => 'siti@panen']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Siti Rahayu')
        );
});

test('the unassigned filter finds accounts that belong to no workspace', function () {
    $operator = User::factory()->superAdmin()->create();
    $orphan = User::factory()->create(['name' => 'Belum Ditempatkan']);
    WorkspaceMember::factory()->owner()->create();

    $this->actingAs($operator)
        ->get(route('users.index', ['status' => 'unassigned']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The operator holds no membership either, but they are not the
            // kind of account this filter is looking for.
            ->has('users.data', 1)
            ->where('users.data.0.id', $orphan->id)
        );
});

test('the detail page shows every workspace an account belongs to', function () {
    $operator = User::factory()->superAdmin()->create();
    $user = User::factory()->create();

    WorkspaceMember::factory()->owner()->for($user)->create();
    WorkspaceMember::factory()->for($user)->create();

    $this->actingAs($operator)
        ->get(route('users.show', $user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('users/show')
            ->where('user.id', $user->id)
            ->has('memberships', 2)
        );
});
