<?php

namespace Wm\WmPackage;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Laravel\Nova\Nova;
use Matchish\ScoutElasticSearch\ElasticSearch\HitsIteratorAggregate;
use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Commands\WmBackupCommand;
use Wm\WmPackage\Commands\WmGeneratePBFCommand;
use Wm\WmPackage\Commands\WmImportFromGeohubCommand;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\ElasticSearch\HitsIteratorAggregate as ElasticSearchHitsIteratorAggregate;
use Wm\WmPackage\Providers\EventServiceProvider;
use Wm\WmPackage\Providers\ScheduleServiceProvider;

class WmPackageServiceProvider extends PackageServiceProvider
{
    public function register()
    {
        // Error handler
        $this->app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            \Wm\WmPackage\Exceptions\Handler::class,

        );

        parent::register();

        $this->app->bind(HitsIteratorAggregate::class, ElasticSearchHitsIteratorAggregate::class);
    }

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
            Route::name('v2.')
                ->middleware('api')
                ->prefix('api/v2')
                ->group($packageDirPath.'routes/api.php');

            Route::name('default.')
                ->middleware('api')
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

        // SENTRY
        $this->app->booted(function () {
            if (app()->bound('sentry')) {
                \Sentry\configureScope(function (\Sentry\State\Scope $scope) {
                    $scope->setTag('app_name', config('app.name'));
                });
            }
        });

        // BACKUP
        // Questo verrà eseguito dopo che tutti i provider sono stati registrati e avviati
        if (! $this->app->runningUnitTests()) {
            $this->app->booted(function () {
                if (class_exists(\Spatie\Backup\Config\Config::class)) {
                    $this->app->config['backup'] = $this->setDefaultBackupSettings();
                    \Spatie\Backup\Config\Config::rebind();
                    $this->commands([WmBackupCommand::class]);
                }
            });
        }
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
                'wm-geohub-import',
                'wm-elasticsearch',
                'wm-horizon',
                'wm-form-schema',
                'wm-tab-translatable',
            ])
            // ->hasRoutes(['api', 'web'])// Check the boot method, routes are registered there
            ->discoversMigrations()
            ->hasCommands([
                WmPackageCommand::class,
                // WmBackupCommand::class,//See in the boot() method
                WmImportFromGeohubCommand::class,
                WmGeneratePBFCommand::class,
            ])
            ->hasViews();
    }

    public function packageRegistered()
    {

        // #######
        // ####### REGISTER PROVIDERS
        // #######

        // This package events
        $this->app->register(EventServiceProvider::class);

        // JWT
        $this->app->register(LaravelServiceProvider::class);

        // ElasticSearch
        $this->app->register(ElasticSearchServiceProvider::class);

        // Schedule
        $this->app->register(ScheduleServiceProvider::class);

        // Register the morphMap for polymorphic relationships
        Relation::morphMap([
            'App\Models\UgcPoi' => \Wm\WmPackage\Models\UgcPoi::class,
            'App\Models\UgcTrack' => \Wm\WmPackage\Models\UgcTrack::class,
            'App\Models\EcPoi' => \Wm\WmPackage\Models\EcPoi::class,
            'App\Models\EcTrack' => \Wm\WmPackage\Models\EcTrack::class,
        ]);

        // #######
        // ####### CONFIGURATIONS OVERRIDE
        // #######

        $this->app->config['filesystems.disks'] = [
            ...$this->app->config['filesystems.disks'],
            ...config('wm-filesystems.disks', []),
        ];

        $this->app->config['tab-translatable'] = config('wm-tab-translatable', []);

        $this->app->config['elasticsearch.indices'] =
            config('wm-elasticsearch.indices', []);

        // // Bind BackupConfig to the container to solve the instantiation error in WmBackupCommand
        // $this->app->scoped(
        //     BackupConfig::class,
        //     function () {
        //         $backupConfig = config('backup');

        //         return BackupConfig::fromArray($backupConfig);
        //     }
        // );

        $this->app->config['media-library'] = array_merge(
            $this->app->config['media-library'] ?? [],
            config('wm-media-library', []),
        );

        // merge geohub database config
        $this->app->config['database.connections'] = array_merge(
            $this->app->config['database.connections'],
            config('wm-geohub-import.connections', []),
        );

        // Configure logging channels
        if (isset($this->app->config['logging.channels'])) {
            $this->app->config['logging.channels'] = array_merge(
                $this->app->config['logging.channels'],
                config('wm-geohub-import.logging.channels', []),
            );
        }

        // Configure Horizon
        if (isset($this->app->config['horizon']) && is_array($this->app->config['horizon'])) {

            // override the horizon config file
            $this->app->config['horizon.environments'] = config('wm-horizon.environments', []);
            $this->app->config['horizon.defaults'] = config('wm-horizon.defaults', []);

            // Get current Horizon config and import config
            $appHorizon = $this->app->config['horizon'];
            $importHorizon = config('wm-geohub-import.horizon', []);

            // Merge environments
            if (isset($importHorizon['environments']) && isset($appHorizon['environments'])) {
                foreach ($importHorizon['environments'] as $env => $supervisors) {
                    if (isset($appHorizon['environments'][$env])) {
                        $appHorizon['environments'][$env] = array_merge(
                            $appHorizon['environments'][$env],
                            $supervisors
                        );
                    } else {
                        $appHorizon['environments'][$env] = $supervisors;
                    }
                }
            }

            // Update the config
            $this->app->config['horizon'] = $appHorizon;
        }
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
