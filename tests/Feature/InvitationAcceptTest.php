<?php

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Auth;

test('an unknown address creates its account and is signed in', function () {
    $workspace = Workspace::factory()->create();
    $invitation = Invitation::factory()->for($workspace)->create(['email' => 'baru@example.test']);

    $this->post(route('invitation.accept', $invitation->token), [
        'name' => 'Orang Baru',
        'password' => 'kata-sandi-rahasia',
        'password_confirmation' => 'kata-sandi-rahasia',
    ])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'baru@example.test')->firstOrFail();

    expect(Auth::id())->toBe($user->id);
    expect(WorkspaceMember::withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

test('holding the link never signs in an account that already exists', function () {
    $workspace = Workspace::factory()->create();
    $existing = User::factory()->create(['email' => 'lama@example.test']);
    $invitation = Invitation::factory()->for($workspace)->create(['email' => 'lama@example.test']);

    $this->post(route('invitation.accept', $invitation->token))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();
    expect(WorkspaceMember::withoutGlobalScopes()
        ->where('user_id', $existing->id)
        ->exists())->toBeFalse();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('a signed in user cannot accept an invitation addressed to someone else', function () {
    $workspace = Workspace::factory()->create();
    User::factory()->create(['email' => 'lama@example.test']);
    $invitation = Invitation::factory()->for($workspace)->create(['email' => 'lama@example.test']);

    $this->actingAs(User::factory()->create());

    $this->post(route('invitation.accept', $invitation->token))
        ->assertRedirect(route('login'));

    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('an existing account that is signed in joins the workspace', function () {
    $workspace = Workspace::factory()->create();
    $existing = User::factory()->create(['email' => 'lama@example.test']);
    $invitation = Invitation::factory()->for($workspace)->create(['email' => 'lama@example.test']);

    $this->actingAs($existing);

    $this->post(route('invitation.accept', $invitation->token))
        ->assertRedirect(route('dashboard'));

    expect(WorkspaceMember::withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $existing->id)
        ->exists())->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('the landing page asks an existing address to sign in first', function () {
    $workspace = Workspace::factory()->create();
    User::factory()->create(['email' => 'lama@example.test']);
    $invitation = Invitation::factory()->for($workspace)->create(['email' => 'lama@example.test']);

    $this->get(route('invitation.show', $invitation->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invitation/accept')
            ->where('needsLogin', true)
            ->where('needsAccount', false)
        );
});

test('an expired invitation is refused', function () {
    $workspace = Workspace::factory()->create();
    $invitation = Invitation::factory()->for($workspace)->expired()->create();

    $this->get(route('invitation.show', $invitation->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitation/invalid'));

    $this->post(route('invitation.accept', $invitation->token))->assertStatus(410);
});
