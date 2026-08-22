<?php

use App\Http\Controllers\OrgUnitController;
use Illuminate\Support\Facades\Route;

/*
 * The org structure, for the platform super admin only.
 *
 * One SAP tree serves every workspace, so this is master data rather than
 * tenant data: no `workspace` middleware, and the controller sees no tenant
 * context, which is what opens the whole tree instead of one company's branch.
 */
Route::middleware(['auth', 'super-admin'])->group(function () {
    Route::get('organization', [OrgUnitController::class, 'index'])->name('organization.index');

    Route::get('org-units/master-search', [OrgUnitController::class, 'search'])->name('org-units.master-search');
    Route::get('org-units/{orgUnit}/children', [OrgUnitController::class, 'children'])->name('org-units.children');
    Route::post('org-units', [OrgUnitController::class, 'store'])->name('org-units.store');
    Route::patch('org-units/{orgUnit}', [OrgUnitController::class, 'update'])->name('org-units.update');
    Route::delete('org-units/{orgUnit}', [OrgUnitController::class, 'destroy'])->name('org-units.destroy');
});
