<?php

namespace Wm\WmPackage;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Nova\Nova;
use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Commands\DownloadDbCommand;
use Wm\WmPackage\Commands\UploadDbAWS;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Providers\EventServiceProvider;

class WmPackageServiceProvider extends PackageServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        $packageDirPath = $this->package->basePath('/../');

        // Register routes as Laravel does with RouteServiceProvider
        // assign the correct group and prefix set on Laravel instance
        $this->app->call(function () use ($packageDirPath) {
            Route::middleware('api')
                ->prefix('api')
                ->group($packageDirPath.'routes/api.php');

            Route::middleware('web')
                ->group($packageDirPath.'routes/web.php');
        });

        // Register policies
        // https://laravel.com/docs/11.x/authorization#registering-policies
        // to check registered policies:
        // ./vendor/bin/testbench tinker --execute "dd(Gate::getPolicyFor("\\Wm\\WmPackage\\Models\\User"))"
        // The procedure below OVERWRITES all application policies.
        // Gate::guessPolicyNamesUsing(function (string $modelClass) {
        //     $t =  "Wm\\WmPackage\\Policies\\" . class_basename($modelClass) . "Policy";
        //     //dump($t);
        //     return $t;
        // });

    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('wm-package')
            ->hasConfigFile([
                'wm-package',
                'wm-filesystems',
            ])
            // ->hasRoutes(['api', 'web'])// Check the boot method, routes are registered there
            ->hasMigrations([
                'create_apps_table',
                'create_ec_poi_ec_track_table',
                'create_ec_pois_table',
                'create_ec_track_layer_table',
                'create_ec_tracks_table',
                'create_favorites_table',
                'create_job_batches_table',
                'create_jobs_table',
                'create_layers_table',
                'create_media_table',
                'create_model_has_permissions_table',
                'create_model_has_roles_table',
                'create_overlay_layer_layer_table',
                'create_overlay_layers_table',
                'create_password_resets_table',
                'create_permissions_table',
                'create_role_has_permissions_table',
                'create_roles_table',
                'create_taxonomy_activities_table',
                'create_taxonomy_activityables_table',
                'create_taxonomy_poi_typeables_table',
                'create_taxonomy_poi_types_table',
                'create_taxonomy_targetables_table',
                'create_taxonomy_targets_table',
                'create_taxonomy_themeables_table',
                'create_taxonomy_themes_table',
                'create_taxonomy_whenables_table',
                'create_taxonomy_whens_table',
                'create_ugc_pois_table',
                'create_ugc_tracks_table',
                'create_users_table',
                'z_add_foreign_keys_to_app_layer_table',
                'z_add_foreign_keys_to_ec_poi_ec_track_table',
                'z_add_foreign_keys_to_ec_pois_table',
                'z_add_foreign_keys_to_ec_track_layer_table',
                'z_add_foreign_keys_to_ec_tracks_table',
                'z_add_foreign_keys_to_model_has_permissions_table',
                'z_add_foreign_keys_to_model_has_roles_table',
                'z_add_foreign_keys_to_overlay_layers_table',
                'z_add_foreign_keys_to_role_has_permissions_table',
                'z_add_foreign_keys_to_taxonomy_activityables_table',
                'z_add_foreign_keys_to_taxonomy_poi_typeables_table',
                'z_add_foreign_keys_to_taxonomy_targetables_table',
                'z_add_foreign_keys_to_taxonomy_themeables_table',
                'z_add_foreign_keys_to_taxonomy_whenables_table',
                'z_add_foreign_keys_to_ugc_pois_table',
                'z_add_foreign_keys_to_ugc_tracks_table',
                'z_add_last_login_at_to_users_table',
            ])
            ->hasCommands([
                WmPackageCommand::class,
                UploadDbAWS::class,
                DownloadDbCommand::class,
            ])
            ->hasViews();
    }

    public function packageRegistered()
    {
        // This package events
        $this->app->register(EventServiceProvider::class);

        // JWT
        $this->app->register(LaravelServiceProvider::class);

        // ElasticSearch
        $this->app->register(ElasticSearchServiceProvider::class);

        $this->app->config['filesystems.disks'] = [
            ...$this->app->config['filesystems.disks'],
            ...config('wm-filesystems.disks'),
        ];
    }

    /**
     * Register the application's Nova resources.
     *
     * @return void
     */
    protected function resources()
    {

        Nova::resourcesIn($this->getPackageBaseDir().'/Nova');
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array
     */
    protected function dashboards()
    {
        return [];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array
     */
    public function tools()
    {
        return [];
    }
}
