<?php

use App\Http\Controllers\OrgUnitController;
use Illuminate\Support\Facades\Route;

/*
 * The org structure.
 *
 * One tree serves every workspace, so this is master data rather than tenant
 * data — hence `workspace:optional`, which resolves the tenant when there is
 * one and never redirects when there is not. A super admin therefore arrives
 * with no tenant context and sees the whole tree, while an Owner or Manager
 * arrives with theirs and sees only the branch their own unit gives them.
 *
 * Who may write what is `OrgUnitPolicy`: roots and anything mirrored from SAP
 * are the operator's, everything a customer drew themselves is the customer's.
 */
Route::middleware(['auth', 'workspace:optional'])->group(function () {
    Route::get('organization', [OrgUnitController::class, 'index'])->name('organization.index');

    Route::get('org-units/{orgUnit}/children', [OrgUnitController::class, 'children'])->name('org-units.children');
    Route::post('org-units', [OrgUnitController::class, 'store'])->name('org-units.store');
    Route::patch('org-units/{orgUnit}', [OrgUnitController::class, 'update'])->name('org-units.update');
    Route::delete('org-units/{orgUnit}', [OrgUnitController::class, 'destroy'])->name('org-units.destroy');
});

/*
 * Searching the untrimmed master tree — used when the operator places a
 * workspace on a node — stays theirs alone. A leader's own search is
 * `org-units.search`, which is scoped to their branch.
 */
Route::middleware(['auth', 'super-admin'])->group(function () {
    Route::get('org-units/master-search', [OrgUnitController::class, 'search'])->name('org-units.master-search');
});
