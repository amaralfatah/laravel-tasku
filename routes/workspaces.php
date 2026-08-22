<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * Workspace administration, for the platform super admin only.
 *
 * No `workspace` middleware: a super admin never enters a workspace (SA-4), so
 * these routes carry no tenant context and scope their own queries.
 */
Route::middleware(['auth', 'super-admin'])
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
