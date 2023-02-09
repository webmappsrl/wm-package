<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\AuthController;
use Wm\WmPackage\Http\Controllers\CallerController;
use Wm\WmPackage\Http\Controllers\ProcessorController;

Route::prefix('api/wm-geobox')->middleware('api')->group(function () {
    // Public routes
    Route::post('login', [AuthController::class, 'login']);

    // Protected routes
    Route::group(['middleware' => ['auth:sanctum']], function () {
        //Returns user details
        Route::get('user', function (Request $request) {
            return $request->user();
        });

        //Logout user
        Route::post('logout', [AuthController::class, 'logout']);

        //Route to handle processor job execution

        // POST /api/wm-geobox/prc/process
        Route::post('prc/process', [ProcessorController::class, 'process']);
        // POST /api/wm-geobox/cll/donedone
        Route::post('cll/donedone', [CallerController::class, 'donedone']);
    });
});
