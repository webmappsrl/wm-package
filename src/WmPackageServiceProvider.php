<?php

namespace Wm\WmPackage;

use Illuminate\Support\Facades\Route;
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
                'add_last_login_at_to_users_table',
                'add_sku_field_to_users',
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
}
