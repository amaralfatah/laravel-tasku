<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a member lands on their own task page', function () {
    $workspace = Workspace::factory()->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4]);

    $this->actingAs($member->user);

    $this->get(route('dashboard'))->assertRedirect(route('monitoring.me'));
});

test('a super admin lands on the workspace roster instead of someone elses workspace', function () {
    Workspace::factory()->create();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->get(route('dashboard'))->assertRedirect(route('workspaces.index'));
});

test('a user without a workspace is sent to the empty state', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertRedirect(route('monitoring.me'));

    $this->get(route('monitoring.me'))
        ->assertRedirect(route('workspace.none'));
});
