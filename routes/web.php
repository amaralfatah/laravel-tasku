<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationAcceptController;
use App\Http\Controllers\WorkspaceContextController;
use Illuminate\Support\Facades\Route;

Route::get('invitations/{token}', [InvitationAcceptController::class, 'show'])->name('invitation.show');
Route::post('invitations/{token}', [InvitationAcceptController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('invitation.accept');

Route::middleware(['auth'])->group(function () {
    // Landing page: the workspace roster for a super admin, "Task saya" for
    // everyone else. Both auth redirects point here so the choice lives in one
    // place (MON-7).
    Route::get('/', HomeController::class)->name('home');
    Route::get('dashboard', HomeController::class)->name('dashboard');

    Route::get('workspace/none', [WorkspaceContextController::class, 'none'])->name('workspace.none');
    // Self-serve: someone who belongs to no workspace starts their own.
    Route::post('workspace/start', [WorkspaceContextController::class, 'start'])
        ->middleware('throttle:6,1')
        ->name('workspace.start');
    Route::post('workspace/{workspace}/change', [WorkspaceContextController::class, 'change'])->name('workspace.change');
});

require __DIR__.'/workspaces.php';
require __DIR__.'/organization.php';
require __DIR__.'/settings.php';
require __DIR__.'/workspace.php';
