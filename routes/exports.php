<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\ExportCsvController;

Route::get('/export-form', [ExportCsvController::class, 'showModelSelection'])->name('export.form');
Route::post('/export-model', [ExportCsvController::class, 'handleModelSelection'])->name('export.model-selection');
Route::post('/export', [ExportCsvController::class, 'exportModel'])->name('export.model');
