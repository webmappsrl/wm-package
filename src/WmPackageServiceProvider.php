<?php

namespace Wm\WmPackage;

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
            ->hasRoutes(['api', 'web'])
            ->hasMigrations([
                'add_last_login_at_to_users_table',
                'add_sku_field_to_users',
            ])
            ->hasCommands([
                WmPackageCommand::class,
                UploadDbAWS::class,
                DownloadDbCommand::class,
            ]);

        $this->app->config['filesystems.disks.backups'] = [
            'driver' => 'local',
            'root' => storage_path('backups'),
        ];
    }

    public function packageRegistered()
    {
        #This package events
        $this->app->register(EventServiceProvider::class);

        #JWT
        $this->app->register(LaravelServiceProvider::class);

        #ElasticSearch
        $this->app->register(ElasticSearchServiceProvider::class);
    }
}
