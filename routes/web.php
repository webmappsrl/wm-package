<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\DownloadDbController;
use Wm\WmPackage\Http\Controllers\ExportDownloadController;
use Wm\WmPackage\Http\Controllers\ImportController;
use Wm\WmPackage\Http\Controllers\RankingController;
use Wm\WmPackage\Http\Controllers\RestoreDbController;
use Wm\WmPackage\Http\Controllers\ShareUgcTrackController;

Route::get('/download-export/{fileName}', [ExportDownloadController::class, 'download'])
    ->name('download.export')
    ->middleware(['web', 'signed']);

Route::get('/download-db', [DownloadDbController::class, 'download'])->name('download.db');

Route::get('/restore-db', [RestoreDbController::class, 'show'])
    ->name('restore.db.show')
    ->middleware(['web', 'auth']);

Route::post('/restore-db', [RestoreDbController::class, 'restore'])
    ->name('restore.db')
    ->middleware(['web', 'auth']);

Route::get('/top-ten/{app}', [RankingController::class, 'showTopTen'])->name('top-ten');
Route::get('/user-ranking/{app}/{user}', [RankingController::class, 'showUserRanking'])->name('user-ranking');

/*
 * Public (unauthenticated) OG-unfurling landing page for a shared UgcTrack (oc:8183, third
 * revision). Not `{app}`-prefixed on purpose: the uuid alone resolves both the track and
 * (internally, for the "app_name" already frozen in properties.share_snapshot) its owning
 * app — see ShareUgcTrackController.
 */
Route::get('/share/ugc-track/{uuid}', [ShareUgcTrackController::class, 'show'])->name('share.ugc-track');

// TODO: Use A middleware to switch the language
// Route::get('language/{locale}', function ($locale) {
//     app()->setLocale($locale);
//     session()->put('locale', $locale);

//     return redirect()->back();
// });

// TODO: security leak, use a middleware to check if the user is authenticated
Route::post('import/geojson', [ImportController::class, 'importGeojson'])->name('import');
Route::post('import/confirm', [ImportController::class, 'saveImport'])->name('save-import');

Route::get('/password/reset', function () {
    return redirect('/nova/password/reset');
});
