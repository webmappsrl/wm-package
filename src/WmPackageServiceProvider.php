<?php

namespace Wm\WmPackage;

use Laravel\Nova\Nova;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Events\ServingNova;
use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Commands\UploadDbAWS;
use Spatie\LaravelPackageTools\Package;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Commands\DownloadDbCommand;
use Wm\WmPackage\Providers\NovaServiceProvider;
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

    /**
     * Register the application's Nova resources.
     *
     * @return void
     */
    protected function resources()
    {

        Nova::resourcesIn($this->getPackageBaseDir() . '/Nova');
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
