<?php

namespace Wm\WmPackage;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Wm\WmPackage\Commands\HoquPingCommand;
use Wm\WmPackage\Commands\HoquRegisterUserCommand;
use Wm\WmPackage\Commands\HoquSendStoreCommand;
use Wm\WmPackage\Commands\HoquUnauthPingCommand;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Commands\UploadDbAWS;
use Wm\WmPackage\Commands\DownloadDbCommand;

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
            ->hasRoute('api')
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
        $this->app->config['filesystems.disks.wmdumps'] = [
            'driver' => 's3',
            'key' => env('AWS_DUMPS_ACCESS_KEY_ID'),
            'secret' => env('AWS_DUMPS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_DUMPS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
        ];
        $this->app->config['filesystems.disks.backups'] = [
            'driver' => 'local',
            'root' => storage_path('backups'),
        ];
    }
}
