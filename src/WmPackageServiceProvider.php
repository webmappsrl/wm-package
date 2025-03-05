<?php

namespace Wm\WmPackage;

use Event;
use Laravel\Nova\Nova;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Wm\WmPackage\Commands\WmBackupCommand;
use Wm\WmPackage\Commands\WmPackageCommand;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed as QueueJobFailed;
use Laravel\Horizon\Events\JobFailed as HorizonJobFailed;
use Spatie\Backup\Config\Config as BackupConfig;
use Wm\WmPackage\Providers\EventServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Providers\ScheduleServiceProvider;
use Wm\WmPackage\Commands\WmImportFromGeohubCommand;
use Wm\WmPackage\Commands\WmCheckGeohubImportCommand;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Wm\WmPackage\Exceptions\Handler as WmExceptionHandler;
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

        // Log failed jobs for both queue and horizon
        Event::listen(QueueJobFailed::class, function (QueueJobFailed $event) {
            WmLogger::failedJobs()->error($event->exception->getMessage(), ['exception' => $event->exception]);
        });

        Event::listen(HorizonJobFailed::class, function (HorizonJobFailed $event) {
            WmLogger::failedJobs()->error($event->exception->getMessage(), ['exception' => $event->exception]);
        });

        $packageDirPath = $this->package->basePath('/../');

        // Register routes as Laravel does with RouteServiceProvider
        // assign the correct group and prefix set on Laravel instance
        $this->app->call(function () use ($packageDirPath) {
            Route::name('v2.')
                ->middleware('api')
                ->prefix('api/v2')
                ->group($packageDirPath . 'routes/api.php');

            Route::name('default.')
                ->middleware('api')
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
                'wm-backup',
                'wm-media-library',
                'wm-database',
                'wm-logging',
            ])
            // ->hasRoutes(['api', 'web'])// Check the boot method, routes are registered there
            ->discoversMigrations()
            ->hasCommands([
                WmPackageCommand::class,
                WmBackupCommand::class,
                WmImportFromGeohubCommand::class,
                WmCheckGeohubImportCommand::class,
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

        // Schedule
        $this->app->register(ScheduleServiceProvider::class);

        // Exception handler
        $this->app->bind(WmExceptionHandler::class, function ($app) {
            return new WmExceptionHandler($app);
        });

        // #######
        // ####### CONFIGURATIONS OVERRIDE
        // #######

        $this->app->config['filesystems.disks'] = [
            ...$this->app->config['filesystems.disks'],
            ...config('wm-filesystems.disks', []),
        ];

        $this->app->config['backup'] = $this->setDefaultBackupSettings();

        // Bind BackupConfig to the container to solve the instantiation error in WmBackupCommand
        $this->app->scoped(
            BackupConfig::class,
            function () {
                $backupConfig = config('backup');

                return BackupConfig::fromArray($backupConfig);
            }
        );

        $this->app->config['media-library'] = array_merge(
            $this->app->config['media-library'] ?? [],
            config('wm-media-library', []),
        );

        // merge geohub database config
        $this->app->config['database.connections'] = array_merge(
            $this->app->config['database.connections'],
            config('wm-database.connections', []),
        );

        // Configure logging channels
        if (isset($this->app->config['logging.channels'])) {
            $this->app->config['logging.channels'] = array_merge(
                $this->app->config['logging.channels'],
                config('wm-logging.channels', []),
            );
        }

        // Register WmLogger facade accessor
        $this->app->bind('wm.logger', function ($app) {
            return $app->make(Log::class);
        });
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

    /**
     * Configure default settings for spatie/laravel-backup
     */
    protected function setDefaultBackupSettings(): array
    {
        $packageConfig = config('wm-backup');
        $appConfig = $this->app->config['backup'];

        $appConfig['backup']['source']['databases'] = $packageConfig['backup']['source']['databases'];
        $appConfig['backup']['database_dump_compressor'] = $packageConfig['backup']['database_dump_compressor'];
        $appConfig['backup']['destination']['disks'] = $packageConfig['backup']['destination']['disks'];
        $appConfig['cleanup'] = $packageConfig['cleanup'];

        return $appConfig;
    }
}
