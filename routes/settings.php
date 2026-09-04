<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WorkspaceController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// `workspace:optional` resolves the active workspace without redirecting, so
// the sidebar keeps its menu and workspace switcher here while settings stay
// reachable for someone who belongs to no workspace yet.
Route::middleware(['auth', 'workspace:optional'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'workspace:optional'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

/*
 * The workspace's own settings, as opposed to the account's. `workspace`
 * rather than `workspace:optional`: there is nothing to rename until one is
 * active, and the page reads the tenant instead of taking an id in the URL.
 */
Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('settings/workspace', [WorkspaceController::class, 'edit'])->name('workspace.settings.edit');
    Route::patch('settings/workspace', [WorkspaceController::class, 'update'])->name('workspace.settings.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
