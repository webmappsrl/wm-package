<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Nova\Fields\FeatureCollectionGrid\Http\Controllers\FeatureCollectionGridController;

Route::get('/geojson/{resourceName}/{resourceId}', [FeatureCollectionGridController::class, 'getGeojson']);
Route::get('/features/{resourceName}/{resourceId}', [FeatureCollectionGridController::class, 'getFeatures']);
Route::post('/sync/{resourceName}/{resourceId}', [FeatureCollectionGridController::class, 'sync']);
Route::get('/widget/{resourceName}/{resourceId}', [FeatureCollectionGridController::class, 'widget']);
