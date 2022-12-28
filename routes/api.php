<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\AuthController;

Route::prefix('api')->middleware('api')->group(function () {

  // Public routes

  Route::post('login', [AuthController::class, 'login']);

  // Protected routes

  /**
   * Only users with special token ability can register users
   */
  Route::post('register', [AuthController::class, 'register'])
    ->middleware(['auth:sanctum', 'abilities:create-users']);

  /**
   *
   */
  Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::get('user', function (Request $request) {
      return $request->user();
    });
    Route::post('logout', [AuthController::class, 'logout']);
  });
});
