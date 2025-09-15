<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController;

Route::get('/{layerId}', [LayerFeatureController::class, 'index']);
Route::get('/features/{layerId}', [LayerFeatureController::class, 'getFeatures']);
Route::post('/sync/{layerId}', [LayerFeatureController::class, 'sync']);
