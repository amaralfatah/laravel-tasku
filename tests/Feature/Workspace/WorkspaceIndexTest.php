<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

test('the workspace roster renders for a super admin', function () {
    Workspace::factory()->create(['name' => 'Perkebunan Nusantara']);

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('workspaces/index')
            ->has('workspaces.data', 1)
            // The page lives in the ordinary app shell, which decides what to
            // show from the user rather than the membership.
            ->where('auth.user.is_super_admin', true)
        );
});

test('the roster stays reachable while no workspace exists yet', function () {
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->get(route('workspaces.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace', null)
            ->where('tenancy.membership', null)
        );
});

test('a regular user cannot manage workspaces', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('workspaces.index'))->assertForbidden();
});
