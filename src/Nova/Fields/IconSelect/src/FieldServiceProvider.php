<?php

namespace Wm\WmPackage\Nova\Fields\IconSelect;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;
use Illuminate\Support\Facades\Route;

class FieldServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::mix('icon-select', __DIR__.'/../dist/mix-manifest.json');
        });

        $this->loadRoutes();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Load the field routes.
     */
    protected function loadRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['nova'])
            ->prefix('nova-vendor/wm/icon-select')
            ->group(function () {
                Route::get('/icons', function () {
                    try {
                        $iconsData = \Wm\WmPackage\Helpers\GlobalFileHelper::getJsonContent('icons.json', 'icons');
                        return response()->json($iconsData);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Unable to load icons'], 500);
                    }
                });
            });
    }
}
