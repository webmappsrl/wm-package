<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\ExportDownloadController;

Route::get('/download-export/{fileName}', [ExportDownloadController::class, 'download'])
    ->name('download.export')
    ->middleware(['web', 'signed']);
