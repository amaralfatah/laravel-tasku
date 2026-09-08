<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\UserMembershipController;
use Illuminate\Support\Facades\Route;

/*
 * Account administration, for the platform super admin only.
 *
 * No `workspace` middleware, for the same reason as `routes/workspaces.php`: a
 * super admin never enters a workspace (SA-4). These routes reach into every
 * tenant's membership table, so each query names its `workspace_id` rather
 * than leaning on `WorkspaceScope`, which is a no-op without a tenant.
 */
Route::middleware(['auth', 'super-admin'])
    ->prefix('users')
    ->name('users.')
    ->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('{user}', [UserController::class, 'show'])->name('show');
        Route::patch('{user}', [UserController::class, 'update'])->name('update');

        Route::post('{user}/password', [UserController::class, 'password'])
            ->middleware('throttle:6,1')
            ->name('password');

        Route::delete('{user}/two-factor', [UserController::class, 'destroyTwoFactor'])
            ->name('two-factor.destroy');

        Route::post('{user}/memberships', [UserMembershipController::class, 'store'])
            ->name('memberships.store');
        Route::patch('{user}/memberships/{member}', [UserMembershipController::class, 'update'])
            ->name('memberships.update');
        Route::delete('{user}/memberships/{member}', [UserMembershipController::class, 'destroy'])
            ->name('memberships.destroy');
    });
