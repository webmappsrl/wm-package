<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\DownloadDbController;
use Wm\WmPackage\Http\Controllers\ExportDownloadController;
use Wm\WmPackage\Http\Controllers\ImportController;
use Wm\WmPackage\Http\Controllers\RankingController;

Route::get('/download-export/{fileName}', [ExportDownloadController::class, 'download'])
    ->name('download.export')
    ->middleware(['web', 'signed']);

Route::get('/download-db', [DownloadDbController::class, 'download'])->name('download.db');

Route::get('/top-ten/{app}', [RankingController::class, 'showTopTen'])->name('top-ten');
Route::get('/user-ranking/{app}/{user}', [RankingController::class, 'showUserRanking'])->name('user-ranking');

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

// Widget Feature Collection Map
Route::get('widget/feature-collection-map', function () {
    $geojsonUrl = request()->get('geojson', 'https://sis-te.com/api/v1/catalog/geohub/1.geojson');

    return view('wm-package::widgets.feature-collection-map', ['geojsonUrl' => $geojsonUrl]);
})->name('widget.feature-collection-map');

// GeoJSON endpoint for hiking route and poles feature collection
Route::get('widget/feature-collection-map-url/{model}/{id}', function ($model, $id) {
    $geojson = null;

    // Usa i modelli configurati nel package o fallback ai modelli di default
    switch ($model) {
        case 'hiking-route':
            $modelClass = config('wm-package.models.hiking_route', 'App\Models\HikingRoute');
            if (class_exists($modelClass)) {
                $hikingRoute = $modelClass::findOrFail($id);
                $geojson = $hikingRoute->getFeatureCollectionMap();
            }
            break;
        case 'poles':
            $modelClass = config('wm-package.models.poles', 'App\Models\Poles');
            if (class_exists($modelClass)) {
                $pole = $modelClass::findOrFail($id);
                $geojson = $pole->getFeatureCollectionMap();
            }
            break;
        default:
            $geojson = null;
            break;
    }

    return response()->json($geojson);
})->name('widget.feature-collection-map-url');
