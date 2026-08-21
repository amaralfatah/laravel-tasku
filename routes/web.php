<?php

use App\Http\Controllers\WorkspaceContextController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('workspace/none', [WorkspaceContextController::class, 'none'])->name('workspace.none');
    Route::post('workspace/{workspace}/change', [WorkspaceContextController::class, 'change'])->name('workspace.change');
});

Route::middleware(['auth', 'workspace'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
