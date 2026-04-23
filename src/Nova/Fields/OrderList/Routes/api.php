<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Nova\Fields\OrderList\src\Http\Controllers\OrderListController;

Route::post('/reorder/{model}/{scopeColumn}/{scopeValue}/{orderColumn}', [OrderListController::class, 'reorder'])
    ->name('order-list.reorder');
