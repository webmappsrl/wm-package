<?php

namespace Wm\WmPackage;

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Commands\UploadDbAWS;
use Spatie\LaravelPackageTools\Package;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Commands\DownloadDbCommand;
use Wm\WmPackage\Providers\EventServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;

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
                ->group($packageDirPath . 'routes/api.php');

            Route::middleware('web')
                ->group($packageDirPath . 'routes/web.php');
        });
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
            ->hasConfigFile()
            //->hasRoutes(['api', 'web'])// Check the boot method, routes are registered there
            ->hasMigrations([
                'add_last_login_at_to_users_table',
                'add_sku_field_to_users',
            ])
            ->hasCommands([
                WmPackageCommand::class,
                UploadDbAWS::class,
                DownloadDbCommand::class,
            ])
            ->hasViews();

        $this->app->config['filesystems.disks.backups'] = [
            'driver' => 'local',
            'root' => storage_path('backups'),
        ];
    }

    public function packageRegistered()
    {
        // This package events
        $this->app->register(EventServiceProvider::class);

        // JWT
        $this->app->register(LaravelServiceProvider::class);

        // ElasticSearch
        $this->app->register(ElasticSearchServiceProvider::class);
    }
}
