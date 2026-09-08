<?php

use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Hash;

/**
 * Creating, repairing and switching off accounts from the operator console
 * (SA-5).
 */
test('the operator creates an account that belongs nowhere yet', function () {
    $operator = User::factory()->superAdmin()->create();

    $this->actingAs($operator)
        ->post(route('users.store'), [
            'name' => 'Budi Santoso',
            'email' => 'budi@panen.test',
            'password' => 'Kata-Sandi-Kuat-1',
            'password_confirmation' => 'Kata-Sandi-Kuat-1',
        ])
        ->assertRedirect();

    $user = User::whereEmail('budi@panen.test')->firstOrFail();

    expect($user->is_active)->toBeTrue()
        ->and($user->is_super_admin)->toBeFalse()
        // Verified on creation: the operator typed the address, and an account
        // that cannot pass verification is one more thing to repair by hand.
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->workspaceMembers()->withoutGlobalScopes()->count())->toBe(0);
});

test('the operator renames and re-addresses an account', function () {
    $operator = User::factory()->superAdmin()->create();
    $user = User::factory()->create();

    $this->actingAs($operator)
        ->patch(route('users.update', $user), [
            'name' => 'Nama Baru',
            'email' => 'baru@panen.test',
        ])
        ->assertRedirect();

    expect($user->refresh()->name)->toBe('Nama Baru')
        ->and($user->email)->toBe('baru@panen.test');
});

test('a deactivated account is refused at login', function () {
    $user = User::factory()->inactive()->create(['email' => 'nonaktif@panen.test']);

    $this->post('/login', [
        'email' => 'nonaktif@panen.test',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('deactivating ends the session that is already open', function () {
    $operator = User::factory()->superAdmin()->create();
    $member = WorkspaceMember::factory()->owner()->create();

    // The account works right up to the moment it is switched off.
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.index'))
        ->assertOk();

    $this->actingAs($operator)
        ->patch(route('users.update', $member->user), ['is_active' => false])
        ->assertRedirect();

    // `fresh()` because a real session rehydrates the user from the database
    // on every request; the in-memory model here predates the change.
    $this->actingAs($member->user->fresh())
        ->withSession(['workspace_id' => $member->workspace_id])
        ->get(route('projects.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('the operator cannot switch off their own account', function () {
    $operator = User::factory()->superAdmin()->create();

    $this->actingAs($operator)
        ->patch(route('users.update', $operator), ['is_active' => false])
        ->assertSessionHasErrors('is_active');

    expect($operator->refresh()->is_active)->toBeTrue();
});

test('a deactivated account can be switched back on', function () {
    $operator = User::factory()->superAdmin()->create();
    $user = User::factory()->inactive()->create();

    $this->actingAs($operator)
        ->patch(route('users.update', $user), ['is_active' => true])
        ->assertRedirect();

    expect($user->refresh()->is_active)->toBeTrue();
});

test('the operator sets a new password without knowing the old one', function () {
    $operator = User::factory()->superAdmin()->create();
    $user = User::factory()->create();

    $this->actingAs($operator)
        ->post(route('users.password', $user), [
            'password' => 'Kata-Sandi-Kuat-1',
            'password_confirmation' => 'Kata-Sandi-Kuat-1',
        ])
        ->assertRedirect();

    expect(Hash::check('Kata-Sandi-Kuat-1', $user->refresh()->password))->toBeTrue()
        // Remembered sessions go with it, or a stolen cookie outlives the reset.
        ->and($user->remember_token)->toBeNull();
});

test('the operator releases a two factor setup nobody can satisfy any more', function () {
    $operator = User::factory()->superAdmin()->create();
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($operator)
        ->delete(route('users.two-factor.destroy', $user))
        ->assertRedirect();

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();
});
