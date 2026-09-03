<?php

use App\Http\Controllers\CommentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\Monitoring\DivisionController;
use App\Http\Controllers\Monitoring\ExportController;
use App\Http\Controllers\Monitoring\PersonController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrgUnitController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectExportController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
 * Paths are English throughout, matching the route and controller names; the
 * interface itself stays Indonesian. Mixing the two in URLs only made links
 * harder to guess.
 */
Route::middleware(['auth', 'workspace'])->group(function () {
    // The structure itself is master data an operator maintains (see
    // routes/organization.php); a leader only searches it to place someone.
    Route::get('org-units/search', [OrgUnitController::class, 'search'])->name('org-units.search');

    // Consolidated view for a holding over its operating companies. Refuses
    // itself when the active workspace runs none, so an ordinary company never
    // has to know the feature exists.
    Route::get('group', [GroupController::class, 'index'])->name('group.index');
    Route::post('group/{company}/enter', [GroupController::class, 'enter'])->name('group.enter');

    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

    Route::post('invitations', [InvitationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('invitations.store');
    Route::post('invitations/{invitation:id}/resend', [InvitationController::class, 'resend'])
        ->middleware('throttle:20,1')
        ->name('invitations.resend');
    Route::delete('invitations/{invitation:id}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('projects/{project}/list', [ProjectController::class, 'list'])->name('projects.list');
    Route::get('projects/{project}/timeline', [ProjectController::class, 'timeline'])->name('projects.timeline');
    Route::get('projects/{project}/timeline/export', ProjectExportController::class)->name('projects.timeline.export');
    Route::get('projects/{project}/settings', [ProjectController::class, 'settings'])->name('projects.settings');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])->name('project-members.store');
    Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->name('project-members.destroy');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('monitoring/people', [PersonController::class, 'index'])->name('monitoring.people');
    Route::get('monitoring/me', [PersonController::class, 'me'])->name('monitoring.me');
    // Registered before the `{member}` route so the literal segment wins.
    Route::get('monitoring/people/export', [ExportController::class, 'people'])->name('monitoring.people.export');
    Route::get('monitoring/people/{member}', [PersonController::class, 'show'])->name('monitoring.person');
    Route::get('monitoring/people/{member}/export', [ExportController::class, 'person'])->name('monitoring.person.export');
    Route::get('monitoring/divisions', [DivisionController::class, 'index'])->name('monitoring.divisions');

    Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
    // Accepting or returning work somebody handed up (TSK-18).
    Route::post('tasks/{task}/review', [TaskController::class, 'review'])->name('tasks.review');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    Route::get('tasks/{task}/comments', [CommentController::class, 'index'])->name('comments.index');
    Route::post('tasks/{task}/comments', [CommentController::class, 'store'])->name('comments.store');
    Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});
