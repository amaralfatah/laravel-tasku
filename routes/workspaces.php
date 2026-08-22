<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * Workspace administration, for the platform super admin only.
 *
 * `workspace:optional` resolves the active workspace when there is one, so the
 * sidebar keeps its context, but never redirects away — this page has to stay
 * reachable when no workspace exists yet.
 */
Route::middleware(['auth', 'super-admin', 'workspace:optional'])
    ->prefix('workspaces')
    ->name('workspaces.')
    ->group(function () {
        Route::get('/', [WorkspaceController::class, 'index'])->name('index');
        Route::post('/', [WorkspaceController::class, 'store'])->name('store');
        Route::patch('{workspace}', [WorkspaceController::class, 'update'])->name('update');
        Route::post('{workspace}/resend-owner-invite', [WorkspaceController::class, 'resendOwnerInvite'])
            ->middleware('throttle:6,1')
            ->name('resend-owner-invite');
    });
