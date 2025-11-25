<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionGrid;

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
            // Register Nova mix if we have frontend assets
            // Temporarily disabled until assets are compiled
            // if (file_exists(__DIR__ . '/../dist/mix-manifest.json')) {
            //     Nova::mix('feature-collection-grid', __DIR__ . '/../dist/mix-manifest.json');
            // }
        });

        // Register views
        $this->loadViewsFrom(__DIR__ . '/../views', 'nova.fields.feature-collection-grid');

        Route::middleware(['nova'])
            ->prefix('nova-vendor/feature-collection-grid')
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
