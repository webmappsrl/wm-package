<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\AppAuthController;
use Wm\WmPackage\Http\Controllers\UgcMediaController;
use Wm\WmPackage\Http\Controllers\UgcPoiController;
use Wm\WmPackage\Http\Controllers\UgcTrackController;

Route::middleware('api')->group(function () {
    Route::post('/auth/login', [AppAuthController::class, 'login'])->name('auth.login');
    Route::middleware('throttle:100,1')->post('/auth/signup', [AppAuthController::class, 'signup'])->name('auth.signup');

    Route::group([
        'middleware' => 'auth.jwt',
        'prefix' => 'auth',
    ], function () {
        Route::post('logout', [AppAuthController::class, 'logout'])->name('auth.logout');
        Route::post('refresh', [AppAuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('me', [AppAuthController::class, 'me'])->name('auth.me');
        Route::post('delete', [AppAuthController::class, 'delete'])->name('auth.delete');
    });

    Route::group([
        'middleware' => 'auth.jwt',
    ], function () {
        Route::prefix('ugc')->name('ugc.')->group(function () {
            Route::prefix('poi')->name('poi.')->group(function () {
                Route::post('store', [UgcPoiController::class, 'store'])->name('store');
                Route::get('index', [UgcPoiController::class, 'index'])->name('index');
                Route::delete('delete/{id}', [UgcPoiController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('track')->name('track.')->group(function () {
                Route::post('store', [UgcTrackController::class, 'store'])->name('store');
                Route::get('index', [UgcTrackController::class, 'index'])->name('index');
                Route::delete('delete/{id}', [UgcTrackController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('media')->name('media.')->group(function () {
                Route::post('store', [UgcMediaController::class, 'store'])->name('store');
                Route::get('index', [UgcMediaController::class, 'index'])->name('index');
                Route::delete('delete/{id}', [UgcMediaController::class, 'destroy'])->name('destroy');
            });
        });
    });
});
