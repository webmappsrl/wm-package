<?php

namespace Wm\WmPackage;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Commands\DownloadDbCommand;
use Wm\WmPackage\Commands\UploadDbAWS;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Providers\EventServiceProvider;

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
            ->hasMigrations(['add_last_login_at_to_users_table'])
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
        $this->app->register(LaravelServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
    }
}
