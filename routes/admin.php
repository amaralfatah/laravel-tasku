<?php

use App\Http\Controllers\Admin\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'super-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/workspaces');

    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::post('workspaces/{workspace}/resend-owner-invite', [WorkspaceController::class, 'resendOwnerInvite'])
        ->middleware('throttle:6,1')
        ->name('workspaces.resend-owner-invite');
});
