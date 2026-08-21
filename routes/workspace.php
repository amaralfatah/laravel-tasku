<?php

use App\Http\Controllers\OrgUnitController;
use App\Http\Controllers\PositionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('organisasi', [OrgUnitController::class, 'index'])->name('organization.index');

    Route::post('org-units', [OrgUnitController::class, 'store'])->name('org-units.store');
    Route::patch('org-units/{orgUnit}', [OrgUnitController::class, 'update'])->name('org-units.update');
    Route::delete('org-units/{orgUnit}', [OrgUnitController::class, 'destroy'])->name('org-units.destroy');

    Route::post('jabatan', [PositionController::class, 'store'])->name('positions.store');
    Route::patch('jabatan/{position}', [PositionController::class, 'update'])->name('positions.update');
    Route::delete('jabatan/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');
});
