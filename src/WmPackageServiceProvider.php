<?php

namespace Wm\WmPackage;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Matchish\ScoutElasticSearch\ElasticSearch\HitsIteratorAggregate;
use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Commands\WmBackupCommand;
use Wm\WmPackage\Commands\WmBuildAppPoisGeojsonCommand;
use Wm\WmPackage\Commands\WmDownloadDbBackupCommand;
use Wm\WmPackage\Commands\WmGeneratePBFCommand;
use Wm\WmPackage\Commands\WmImportFromGeohubCommand;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\ElasticSearch\HitsIteratorAggregate as ElasticSearchHitsIteratorAggregate;
use Wm\WmPackage\Jobs\Import\ImportEcMediaJob;
use Wm\WmPackage\Providers\EventServiceProvider;
use Wm\WmPackage\Providers\ScheduleServiceProvider;
use Wm\WmPackage\Services\Import\EcMediaImportService;
use Wm\WmPackage\Services\Import\GeohubImportService;

class WmPackageServiceProvider extends PackageServiceProvider
{
    public function register()
    {
        // Error handler
        $this->app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            Exceptions\Handler::class,

        );

        parent::register();

        $this->app->bind(HitsIteratorAggregate::class, ElasticSearchHitsIteratorAggregate::class);

        // Registra il GlobalFileServiceProvider
        $this->app->register(GlobalFileServiceProvider::class);

        // Registra IconSelect FieldServiceProvider
        $this->app->register(\Wm\WmPackage\Nova\Fields\IconSelect\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\LayerFeatures\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FieldServiceProvider::class);
    }

    public static function getBasePath(): string
    {
        /** @var WmPackageServiceProvider $provider */
        $provider = app()->getProvider(static::class);

        return realpath($provider->package->basePath('/../'));
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

        // Register Nova CSS assets
        Nova::serving(function () {
            Nova::style('wm-flexible-field', __DIR__ . '/../resources/css/flexible-field.css');
            $this->addWmpackageToolsMenuItem();
        });

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
        //     $t =  "Wm\\WmPackage\\Policies\\".class_basename($modelClass)."Policy";
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
                if (class_exists(BackupConfig::class)) {
                    $this->app->config['backup'] = $this->setDefaultBackupSettings();
                    BackupConfig::rebind();
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
                'wm-minio',
                'wm-horizon',
                'wm-form-schema',
                'wm-tab-translatable',
                'wm-layer-schema',
                'wm-ec-track-schema',
                'wm-ec-from-ugc-schema',
            ])
            // ->hasRoutes(['api', 'web'])// Check the boot method, routes are registered there
            ->discoversMigrations()
            ->hasCommands([
                WmPackageCommand::class,
                // WmBackupCommand::class,//See in the boot() method
                WmImportFromGeohubCommand::class,
                WmGeneratePBFCommand::class,
                WmDownloadDbBackupCommand::class,
                WmBuildAppPoisGeojsonCommand::class,
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

        // Register the correct import service for the ImportEcMediaJob
        $this->app->when(ImportEcMediaJob::class)
            ->needs(GeohubImportService::class)
            ->give(function () {
                return new EcMediaImportService;
            });

        // Register the morphMap for polymorphic relationships
        Relation::morphMap([
            'App\Models\UgcPoi' => Models\UgcPoi::class,
            'App\Models\UgcTrack' => Models\UgcTrack::class,
            'App\Models\EcPoi' => Models\EcPoi::class,
            'App\Models\EcTrack' => Models\EcTrack::class,
            'App\Models\Layer' => Models\Layer::class,
            'App\Models\App' => Models\App::class,
        ]);

        // #######
        // ####### CONFIGURATIONS OVERRIDE
        // #######

        $this->app->config['filesystems.disks'] = [
            ...config('wm-filesystems.disks', []),
            ...$this->app->config['filesystems.disks'],
        ];

        $this->app->config['tab-translatable'] = config('wm-tab-translatable', []);

        // Merge elasticsearch configuration from wm-elasticsearch
        $wmElasticsearchConfig = config('wm-elasticsearch', []);
        if (isset($wmElasticsearchConfig['host'])) {
            $this->app->config['elasticsearch.host'] = $wmElasticsearchConfig['host'];
        }
        if (isset($wmElasticsearchConfig['user'])) {
            $this->app->config['elasticsearch.user'] = $wmElasticsearchConfig['user'];
        }
        if (isset($wmElasticsearchConfig['password'])) {
            $this->app->config['elasticsearch.password'] = $wmElasticsearchConfig['password'];
        }
        if (isset($wmElasticsearchConfig['cloud_id'])) {
            $this->app->config['elasticsearch.cloud_id'] = $wmElasticsearchConfig['cloud_id'];
        }
        if (isset($wmElasticsearchConfig['api_key'])) {
            $this->app->config['elasticsearch.api_key'] = $wmElasticsearchConfig['api_key'];
        }
        if (isset($wmElasticsearchConfig['ssl_verification'])) {
            $this->app->config['elasticsearch.ssl_verification'] = $wmElasticsearchConfig['ssl_verification'];
        }
        if (isset($wmElasticsearchConfig['queue'])) {
            $this->app->config['elasticsearch.queue'] = $wmElasticsearchConfig['queue'];
        }
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
            // Merge wm-package horizon config (non-overwriting)
            $appHorizonConfig = $this->app->config['horizon'] ?? [];
            $wmPackageHorizonConfig = config('wm-horizon', []);
            $appHorizonConfig = $this->mergeHorizonConfigs($appHorizonConfig, $wmPackageHorizonConfig);

            // Merge wm-geohub-import horizon config (potentially overwriting environments)
            $importHorizonConfig = config('wm-geohub-import.horizon', []);
            if (isset($importHorizonConfig['environments']) && isset($appHorizonConfig['environments'])) {
                foreach ($importHorizonConfig['environments'] as $env => $supervisors) {
                    // Ensure the environment exists before merging
                    if (! isset($appHorizonConfig['environments'][$env])) {
                        $appHorizonConfig['environments'][$env] = [];
                    }
                    // Merge supervisors for the specific environment using array_merge (overwrites)
                    $appHorizonConfig['environments'][$env] = array_merge(
                        $appHorizonConfig['environments'][$env],
                        $supervisors
                    );
                }
            }

            // Update the application's final horizon configuration
            $this->app->config['horizon'] = $appHorizonConfig;
        }
    }

    /**
     * Merges Horizon configuration from a source array into a target array (non-overwriting).
     * Prioritizes keys already existing in the target array.
     *
     * @param  array  $target  The application's Horizon configuration.
     * @param  array  $source  The package's Horizon configuration.
     * @return array The merged Horizon configuration.
     */
    private function mergeHorizonConfigs(array $target, array $source): array
    {
        // Merge 'defaults' section (non-overwriting)
        $mergedDefaults = $target['defaults'] ?? [];
        foreach ($source['defaults'] ?? [] as $key => $value) {
            if (! isset($mergedDefaults[$key])) {
                $mergedDefaults[$key] = $value;
            }
        }
        $target['defaults'] = $mergedDefaults;

        // Merge 'environments' section (non-overwriting for supervisors within each environment)
        $mergedEnvironments = $target['environments'] ?? [];
        foreach ($source['environments'] ?? [] as $env => $sourceSupervisors) {
            // Ensure the environment array exists in the target
            if (! isset($mergedEnvironments[$env])) {
                $mergedEnvironments[$env] = [];
            }
            // Merge supervisors for the current environment (non-overwriting)
            foreach ($sourceSupervisors as $supervisorName => $supervisorConfig) {
                if (! isset($mergedEnvironments[$env][$supervisorName])) {
                    $mergedEnvironments[$env][$supervisorName] = $supervisorConfig;
                }
            }
        }
        $target['environments'] = $mergedEnvironments;

        return $target; // Return the modified target array
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

    protected function addWmpackageToolsMenuItem()
    {
        $createHorizonMenuItem = function () {
            $menuItem = MenuItem::externalLink(__('Horizon'), url('/horizon'))
                ->canSee(fn() => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };
        $createDownloadDbMenuItem = function () {
            $menuItem = MenuItem::externalLink(__('Download DB'), route('download.db'))
                ->canSee(fn() => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };
        $createMinioMenuItem = function () {
            // Determina l'URL in base all'ambiente
            $environment = app()->environment();
            if ($environment === 'local') {
                $url = config('wm-minio.console_url', 'http://localhost:9003');
            } elseif ($environment === 'production') {
                // Non mostrare in produzione
                return null;
            } else {
                // Staging, testing, ecc.
                $url = url('/minio');
            }

            $menuItem = MenuItem::externalLink(__('Minio'), $url)
                ->canSee(fn() => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };
        $createKibanaMenuItem = function () {
            // Determina l'URL in base all'ambiente
            $environment = app()->environment();
            if ($environment === 'local') {
                $url = 'http://0.0.0.0:5601';
            } elseif ($environment === 'production') {
                // Non mostrare in produzione
                return null;
            } else {
                // Staging, testing, ecc.
                $url = url('/kibana');
            }
            $menuItem = MenuItem::externalLink(__('Kibana'), $url)
                ->canSee(fn() => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };

        if (Nova::$mainMenuCallback) {
            $originalCallback = Nova::$mainMenuCallback;

            Nova::mainMenu(function (Request $request) use ($originalCallback, $createDownloadDbMenuItem, $createMinioMenuItem, $createHorizonMenuItem, $createKibanaMenuItem) {
                $menuItems = call_user_func($originalCallback, $request);
                $downloadDbMenuItem = $createDownloadDbMenuItem();
                $minioMenuItem = $createMinioMenuItem();
                $horizonMenuItem = $createHorizonMenuItem();
                $kibanaMenuItem = $createKibanaMenuItem();

                $toolsSectionFound = false;
                foreach ($menuItems as $index => &$sectionOrGroup) {
                    if (
                        $sectionOrGroup instanceof MenuSection &&
                        $sectionOrGroup->name === __('Tools')
                    ) {
                        $toolsSectionFound = true;
                        try {
                            $reflection = new \ReflectionObject($sectionOrGroup);

                            $itemsProperty = $reflection->getProperty('items');
                            $itemsProperty->setAccessible(true);
                            $currentItems = $itemsProperty->getValue($sectionOrGroup);
                            if ($horizonMenuItem !== null) {
                                $currentItems[] = $horizonMenuItem;
                            }
                            if ($minioMenuItem !== null) {
                                $currentItems[] = $minioMenuItem;
                            }
                            if ($kibanaMenuItem !== null) {
                                $currentItems[] = $kibanaMenuItem;
                            }
                            $currentItems[] = $downloadDbMenuItem;

                            $icon = $reflection->getProperty('icon');
                            $icon->setAccessible(true);
                            $iconValue = $icon->getValue($sectionOrGroup);

                            $collapsable = $reflection->getProperty('collapsable');
                            $collapsable->setAccessible(true);
                            $collapsableValue = $collapsable->getValue($sectionOrGroup);

                            $menuItems[$index] = MenuSection::make($sectionOrGroup->name, $currentItems)
                                ->icon($iconValue)
                                ->collapsable($collapsableValue);
                        } catch (\ReflectionException $e) {
                            logger()->error(
                                'WM-Package: Failed to modify Nova Tools menu section via reflection. Exception: ' . $e->getMessage()
                            );
                        }
                        break;
                    }
                }
                unset($sectionOrGroup);

                // Se la sezione Tools non esiste, la creiamo
                if (! $toolsSectionFound) {
                    $toolsItems = [];
                    if ($horizonMenuItem !== null) {
                        $toolsItems[] = $horizonMenuItem;
                    }
                    if ($minioMenuItem !== null) {
                        $toolsItems[] = $minioMenuItem;
                    }
                    if ($kibanaMenuItem !== null) {
                        $toolsItems[] = $kibanaMenuItem;
                    }
                    $toolsItems[] = $downloadDbMenuItem;

                    $menuItems[] = MenuSection::make(__('Tools'), $toolsItems)->icon('briefcase')
                        ->collapsable();
                }

                return $menuItems;
            });
        } else {
            Nova::mainMenu(function (Request $request) use ($createDownloadDbMenuItem, $createMinioMenuItem, $createHorizonMenuItem, $createKibanaMenuItem) {
                $toolsItems = [$createDownloadDbMenuItem()];
                $minioMenuItem = $createMinioMenuItem();
                if ($minioMenuItem !== null) {
                    $toolsItems[] = $minioMenuItem;
                }
                $horizonMenuItem = $createHorizonMenuItem();
                if ($horizonMenuItem !== null) {
                    $toolsItems[] = $horizonMenuItem;
                }
                $kibanaMenuItem = $createKibanaMenuItem();
                if ($kibanaMenuItem !== null) {
                    $toolsItems[] = $kibanaMenuItem;
                }

                return [
                    MenuSection::make(__('Tools'), $toolsItems)
                        ->icon('color-swatch')
                        ->collapsable(),
                ];
            });
        }
    }
}
