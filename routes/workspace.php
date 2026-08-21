<?php

use App\Http\Controllers\CommentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\Monitoring\DivisionController;
use App\Http\Controllers\Monitoring\PersonController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrgUnitController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('organisasi', [OrgUnitController::class, 'index'])->name('organization.index');

    Route::post('org-units', [OrgUnitController::class, 'store'])->name('org-units.store');
    Route::patch('org-units/{orgUnit}', [OrgUnitController::class, 'update'])->name('org-units.update');
    Route::delete('org-units/{orgUnit}', [OrgUnitController::class, 'destroy'])->name('org-units.destroy');

    Route::get('anggota', [MemberController::class, 'index'])->name('members.index');
    Route::patch('anggota/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('anggota/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

    Route::post('undangan', [InvitationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('invitations.store');
    Route::post('undangan/{invitation:id}/kirim-ulang', [InvitationController::class, 'resend'])
        ->middleware('throttle:20,1')
        ->name('invitations.resend');
    Route::delete('undangan/{invitation:id}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('projects/{project}/daftar', [ProjectController::class, 'list'])->name('projects.list');
    Route::get('projects/{project}/timeline', [ProjectController::class, 'timeline'])->name('projects.timeline');
    Route::get('projects/{project}/pengaturan', [ProjectController::class, 'settings'])->name('projects.settings');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('projects/{project}/anggota', [ProjectMemberController::class, 'store'])->name('project-members.store');
    Route::delete('projects/{project}/anggota/{user}', [ProjectMemberController::class, 'destroy'])->name('project-members.destroy');

    Route::get('notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifikasi/{notification}/baca', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('monitoring/orang', [PersonController::class, 'index'])->name('monitoring.people');
    Route::get('monitoring/saya', [PersonController::class, 'me'])->name('monitoring.me');
    Route::get('monitoring/orang/{member}', [PersonController::class, 'show'])->name('monitoring.person');
    Route::get('monitoring/divisi', [DivisionController::class, 'index'])->name('monitoring.divisions');

    Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('tasks/{task}/pindah', [TaskController::class, 'move'])->name('tasks.move');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    Route::get('tasks/{task}/komentar', [CommentController::class, 'index'])->name('comments.index');
    Route::post('tasks/{task}/komentar', [CommentController::class, 'store'])->name('comments.store');
    Route::patch('komentar/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('komentar/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});
