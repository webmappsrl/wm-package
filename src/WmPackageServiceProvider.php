<?php

namespace Wm\WmPackage;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
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
        $package
            ->name('wm-package')
            ->hasViews('wm-package')
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

        // Register additional configurations for your package
        $this->registerFilesystemConfigurations();

        // Publish the necessary package components
        $this->publishPackageAssets();
    }

    /**
     * Register any filesystem configurations needed by the package.
     */
    private function registerFilesystemConfigurations(): void
    {
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

    /**
     * Publish package assets like routes, controllers, exports, and views.
     */
    private function publishPackageAssets(): void
    {
        $this->publishes([
            __DIR__.'/../config/wm-csv-export.php' => config_path('wm-csv-export.php'),
        ], 'wm-package-config');

        $this->publishes([
            __DIR__.'/../src/Http/Controllers' => app_path('Http/Controllers/WmPackage'),
        ], 'wm-package-controllers');

        $this->publishes([
            __DIR__.'/../src/Exports' => app_path('Exports/WmPackage'),
        ], 'wm-package-exports');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/wm-package'),
        ], 'wm-package-views');

        $this->publishes([
            __DIR__.'/../routes/exports.php' => base_path('routes/wm-package-export-routes.php'),
        ], 'wm-package-routes');
    }

    public function bootingPackage()
    {
        if (file_exists(base_path('routes/wm-package-export-routes.php'))) {
            \Log::info('Loading routes from application: routes/wm-package-export-routes.php');
            $this->loadRoutesFrom(base_path('routes/wm-package-export-routes.php'));
        } else {
            \Log::info('Loading routes from package: routes/exports.php');
            $this->loadRoutesFrom(__DIR__.'/../routes/exports.php');
        }
    }
}
