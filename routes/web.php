<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\ImportController;
use Wm\WmPackage\Http\Controllers\RankingController;
use Wm\WmPackage\Http\Controllers\ExportDownloadController;

Route::get('/download-export/{fileName}', [ExportDownloadController::class, 'download'])
    ->name('download.export')
    ->middleware(['web', 'signed']);

Route::get('/top-ten/{app}', [RankingController::class, 'showTopTen'])->name('top-ten');
Route::get('/user-ranking/{app}/{user}', [RankingController::class, 'showUserRanking'])->name('user-ranking');

// TODO: Use A middleware to switch the language
// Route::get('language/{locale}', function ($locale) {
//     app()->setLocale($locale);
//     session()->put('locale', $locale);

//     return redirect()->back();
// });

//TODO: security leak, use a middleware to check if the user is authenticated
Route::post('import/geojson', [ImportController::class, 'importGeojson'])->name('import');
Route::post('import/confirm', [ImportController::class, 'saveImport'])->name('save-import');
