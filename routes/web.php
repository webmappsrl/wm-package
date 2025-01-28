<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\ExportDownloadController;
use Wm\WmPackage\Http\Controllers\RankingController;

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
