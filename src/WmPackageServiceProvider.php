<?php

namespace Wm\WmPackage;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Commands\DownloadDbCommand;
use Wm\WmPackage\Commands\HoquPingCommand;
use Wm\WmPackage\Commands\HoquRegisterUserCommand;
use Wm\WmPackage\Commands\HoquSendStoreCommand;
use Wm\WmPackage\Commands\HoquUnauthPingCommand;
use Wm\WmPackage\Commands\UploadDbAWS;
use Wm\WmPackage\Commands\WmPackageCommand;

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
            ->hasConfigFile('jwt')
            ->hasRoutes(['api'])
            //->hasViews()
            ->hasMigrations([
                'create_jobs_table',
                'create_hoqu_caller_jobs_table',
            ])
            ->hasCommands([
                WmPackageCommand::class,
                HoquRegisterUserCommand::class,
                HoquSendStoreCommand::class,
                HoquPingCommand::class,
                HoquUnauthPingCommand::class,
                UploadDbAWS::class,
                DownloadDbCommand::class,
            ]);

        // Pubblica la configurazione JWT
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/jwt.php' => config_path('jwt.php'),
            ], 'wm-package-jwt-config');
        }

        // $this->app->config['filesystems.disks.wmdumps'] = [
        //     'driver' => 's3',
        //     'key' => env('AWS_DUMPS_ACCESS_KEY_ID'),
        //     'secret' => env('AWS_DUMPS_SECRET_ACCESS_KEY'),
        //     'region' => env('AWS_DEFAULT_REGION'),
        //     'bucket' => env('AWS_DUMPS_BUCKET'),
        //     'url' => env('AWS_URL'),
        //     'endpoint' => env('AWS_ENDPOINT'),
        // ];
        $this->app->config['filesystems.disks.backups'] = [
            'driver' => 'local',
            'root' => storage_path('backups'),
        ];
    }

    public function packageBooted()
    {
        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
    }

    public function packageRegistered()
    {
        $this->app->register(LaravelServiceProvider::class);
    }
}
