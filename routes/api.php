<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\AuthController;

Route::prefix('api/wm')->middleware('api')->group(function () {
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
        Route::post('processor-do', [ProcessorDo::class]);
    });
});
