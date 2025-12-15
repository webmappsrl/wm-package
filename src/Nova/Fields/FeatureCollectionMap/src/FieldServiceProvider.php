<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

class FieldServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::script('feature-collection-map', __DIR__.'/../dist/js/field.js');
            Nova::style('feature-collection-map', __DIR__.'/../dist/css/field.css');
        });

        // Registra le view del FeatureCollectionMap (per fallback)
        $this->loadViewsFrom(__DIR__.'/../views', 'nova.fields.feature-collection-map');

        // Registra le route del FeatureCollectionMap
        $this->loadRoutes();
    }

    /**
     * Load the field routes.
     */
    protected function loadRoutes(): void
    {
        Route::middleware(['nova'])
            ->prefix('nova-vendor/feature-collection-map')
            ->group(__DIR__.'/../Routes/api.php');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
