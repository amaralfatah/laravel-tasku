<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Inertia\Testing\AssertableInertia;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('the settings pages resolve the active workspace so the sidebar keeps its menu', function () {
    // The sidebar builds its menu from `tenancy.workspace`, so a settings page
    // that skips workspace resolution renders an empty sidebar.
    $workspace = Workspace::factory()->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => WorkspaceRole::Bod4]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $workspace->id])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace.id', $workspace->id)
            ->where('tenancy.membership.role_code', WorkspaceRole::Bod4->code())
        );
});

test('settings stay reachable for a user who belongs to no workspace', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.workspace', null)
        );
});
