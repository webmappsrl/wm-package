<?php

namespace Wm\WmPackage;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Matchish\ScoutElasticSearch\ElasticSearch\HitsIteratorAggregate;
use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;
use Sentry\State\Scope;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Wm\WmPackage\Commands\WmBackfillGravatarAvatarsCommand;
use Wm\WmPackage\Commands\WmBackupCommand;
use Wm\WmPackage\Commands\WmBuildAppPoisGeojsonCommand;
use Wm\WmPackage\Commands\WmDownloadDbBackupCommand;
use Wm\WmPackage\Commands\WmGenerateIconsCommand;
use Wm\WmPackage\Commands\WmGeneratePBFCommand;
use Wm\WmPackage\Commands\WmImportEcPoiFromOsmCommand;
use Wm\WmPackage\Commands\WmImportFromGeohubCommand;
use Wm\WmPackage\Commands\WmPackageCommand;
use Wm\WmPackage\Commands\WmPackagePublishMigrationCommand;
use Wm\WmPackage\Commands\WmPackagePublishMissingMigrationsCommand;
use Wm\WmPackage\Commands\WmRestoreDbCommand;
use Wm\WmPackage\Commands\WmSyncUgcTaxonomyWhereCommand;
use Wm\WmPackage\ElasticSearch\HitsIteratorAggregate as ElasticSearchHitsIteratorAggregate;
use Wm\WmPackage\Http\Controllers\Nova\AnalyticsController;
use Wm\WmPackage\Http\Controllers\Nova\GeohubWhereSelectionController;
use Wm\WmPackage\Jobs\Import\ImportEcMediaJob;
use Wm\WmPackage\Jobs\Import\ImportUgcMediaJob;
use Wm\WmPackage\Models\App as AppModel;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\CardServiceProvider;
use Wm\WmPackage\Nova\Cards\LayerAnalytics\CardServiceProvider as LayerAnalyticsCardServiceProvider;
use Wm\WmPackage\Nova\Fields\IconSelect\FieldServiceProvider;
use Wm\WmPackage\Policies\AppPolicy;
use Wm\WmPackage\Providers\EventServiceProvider;
use Wm\WmPackage\Providers\ScheduleServiceProvider;
use Wm\WmPackage\Services\FeaturesService;
use Wm\WmPackage\Services\Import\EcMediaImportService;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Services\Import\UgcMediaImportService;
use Wm\WmPackage\Tests\Feature\OptionalDomainRegistrationTest;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode;

class WmPackageServiceProvider extends PackageServiceProvider
{
    public function register()
    {
        // Error handler
        $this->app->singleton(
            ExceptionHandler::class,
            Exceptions\Handler::class,

        );

        parent::register();

        $this->app->bind(HitsIteratorAggregate::class, ElasticSearchHitsIteratorAggregate::class);

        // Registra il GlobalFileServiceProvider
        $this->app->register(GlobalFileServiceProvider::class);

        // Registra IconSelect FieldServiceProvider
        $this->app->register(FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\LayerFeatures\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\FeatureCollectionMap\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\BboxField\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\FeatureCollectionGrid\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\OrderList\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\TrackColor\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\TranslationsBuilder\FieldServiceProvider::class);
        $this->app->register(\Wm\WmPackage\Nova\Fields\PoiTrackReferenceField\FieldServiceProvider::class);
        $this->app->register(CardServiceProvider::class);
        $this->app->register(LayerAnalyticsCardServiceProvider::class);
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
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang');

        $packageDirPath = $this->package->basePath('/../');

        // Register Nova CSS/JS assets
        Nova::serving(function () {
            Nova::style('wm-flexible-field', __DIR__.'/../resources/css/flexible-field.css');
            Nova::style('wm-nova-overrides', __DIR__.'/../resources/css/nova.css');
            Nova::style('wm-nova-dropzone', __DIR__.'/../resources/css/nova-dropzone.css');
            Nova::script('wm-nova-overrides', __DIR__.'/../resources/js/nova.js');
            Nova::script('wm-geohub-where-selection', __DIR__.'/../resources/js/geohub-where-selection.js');
            $this->addWmpackageToolsMenuItem();
        });

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

            Route::middleware(['nova'])
                ->prefix('nova-vendor/layer-analytics')
                ->group(function () {
                    Route::get('/global', [AnalyticsController::class, 'global']);
                    Route::get('/{layer}', [AnalyticsController::class, 'layer']);
                });

            Route::middleware(['nova'])
                ->prefix('nova-vendor/geohub-where-selection')
                ->group(function () {
                    Route::post('/import', [GeohubWhereSelectionController::class, 'import']);
                });
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
        Gate::policy(AppModel::class, AppPolicy::class);

        // SENTRY
        $this->app->booted(function () {
            if (app()->bound('sentry')) {
                \Sentry\configureScope(function (Scope $scope) {
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
                'wm-excel-ec-import',
                'wm-elasticsearch',
                'wm-minio',
                'wm-horizon',
                'wm-form-schema',
                'wm-tab-translatable',
                'wm-layer-schema',
                'wm-ec-track-schema',
                'wm-ec-poi-schema',
                'wm-ec-from-ugc-schema',
                'wm-app-languages',
                'wm-logging',
                'wm-osm-import',
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
                WmSyncUgcTaxonomyWhereCommand::class,
                WmBackfillGravatarAvatarsCommand::class,
                WmRestoreDbCommand::class,
                WmGenerateIconsCommand::class,
                WmPackagePublishMigrationCommand::class,
                WmPackagePublishMissingMigrationsCommand::class,
                WmImportEcPoiFromOsmCommand::class,
            ])
            ->hasViews();
    }

    /**
     * Registra cio' che appartiene ai domini opzionali accesi.
     *
     * Copre le quattro superfici che un dominio puo' toccare — comandi, route,
     * risorse Nova, voci di menu — perche' la promessa del meccanismo e' che a
     * dominio spento il package si comporti come se il dominio non esistesse.
     *
     * **Vincolo strutturale, non convenzione:** le risorse Nova di un dominio
     * NON possono vivere in `src/Nova`. `Nova::resourcesIn()` scandisce quella
     * cartella in modo ricorsivo e registra tutto cio' che ci trova, a
     * prescindere da questo metodo. Vanno dichiarate in
     * `config('wm-package.features.<dominio>.nova_resources')` e collocate
     * altrove. Il vincolo e' verificato da
     * {@see OptionalDomainRegistrationTest}.
     *
     * @see FeaturesService
     * @see docs/resources/OptionalDomains.md
     */
    protected function registerEnabledDomains(): void
    {
        foreach (FeaturesService::enabledDomains() as $domain) {
            $commands = (array) config("wm-package.features.{$domain}.commands", []);

            if ($commands !== []) {
                $this->commands($commands);
            }

            $novaResources = (array) config("wm-package.features.{$domain}.nova_resources", []);

            if ($novaResources !== [] && class_exists(Nova::class)) {
                Nova::resources($novaResources);
            }

            $routes = $this->getPackageBaseDir()."/../routes/domains/{$domain}.php";

            if (file_exists($routes)) {
                $this->loadRoutesFrom($routes);
            }

            // Un dominio puo' portarsi dietro un po' di JavaScript — per ora
            // solo la registrazione dei componenti delle sue card. Si carica
            // qui, non fra gli script di base, cosi' chi non ha acceso il
            // dominio non se lo ritrova nel proprio pannello.
            $script = $this->getPackageBaseDir()."/../resources/js/domains/{$domain}.js";

            if (file_exists($script) && class_exists(Nova::class)) {
                Nova::script("wm-domain-{$domain}", $script);
            }
        }
    }

    /**
     * Accoda $items a una MenuSection esistente chiamata $sectionName, o la
     * crea con quegli item se il consumer non ne ha una con quel nome.
     *
     * Stesso meccanismo gia' in uso per la sezione "Tools" (via reflection,
     * perche' MenuSection non espone un modo pubblico per leggere i propri
     * item/icon/collapsable e ricostruirla accodando) — estratto qui perche'
     * anche la sezione "Catasto" del dominio trail_registry lo riusa,
     * evitando un secondo meccanismo parallelo.
     *
     * @param  array<int, mixed>  $menuItems
     * @param  array<int, MenuItem>  $items
     * @return array<int, mixed>
     */
    protected function injectMenuSectionItems(array $menuItems, string $sectionName, array $items, string $icon): array
    {
        foreach ($menuItems as $index => $sectionOrGroup) {
            if (! $sectionOrGroup instanceof MenuSection || $sectionOrGroup->name !== $sectionName) {
                continue;
            }

            // La sezione va ricostruita, non modificata: `items` e' protetta e
            // MenuSection non espone un modo per accodare. Tutto il resto —
            // icona, richiudibilita', stato iniziale — sono proprieta'
            // pubbliche del trait Collapsable, quindi si leggono e si
            // riportano senza reflection.
            //
            // Le voci del package vanno PRIMA di quelle gia' dichiarate dal
            // consumer: sono il contenuto della sezione — i dati su cui si
            // lavora — mentre quelle del consumer sono aggiunte, tipicamente
            // collegamenti a documentazione o strumenti esterni, che stanno
            // meglio in coda.
            $rebuilt = MenuSection::make(
                $sectionOrGroup->name,
                array_merge($items, $this->menuSectionItems($sectionOrGroup)),
            )->icon($sectionOrGroup->icon ?? $icon);

            // `collapsedByDefault()` chiama gia' `collapsable()`: chiamarli
            // entrambi renderebbe richiudibile anche una sezione che non lo
            // era. Si riporta lo stato piu' specifico dei due.
            if ($sectionOrGroup->collapsedByDefault) {
                $rebuilt->collapsedByDefault();
            } elseif ($sectionOrGroup->collapsable) {
                $rebuilt->collapsable();
            }

            $menuItems[$index] = $rebuilt;

            return $menuItems;
        }

        // Il consumer non ha dichiarato la sezione: la si crea in fondo, con
        // le voci del package. Chi la vuole altrove — o chiusa di default —
        // la dichiara nel proprio menu, anche vuota, e questo metodo la
        // riempie lasciandola dov'e'.
        $menuItems[] = MenuSection::make($sectionName, $items)->icon($icon)->collapsedByDefault();

        return $menuItems;
    }

    /**
     * Le voci gia' presenti in una sezione. `items` e' protetta: e' l'unica
     * cosa per cui serve ancora la reflection, e se un giorno Nova la
     * rendesse pubblica questo metodo sparirebbe.
     *
     * **Non e' un array**: Nova ci mette una `MenuCollection`. Un controllo
     * `is_array()` la scarterebbe in silenzio, e le voci che il consumer ha
     * dichiarato nella sezione sparirebbero nel momento in cui il package ci
     * appende le proprie — e' successo davvero, con la voce della
     * documentazione API dichiarata in «Catasto».
     *
     * @return array<int, mixed>
     */
    protected function menuSectionItems(MenuSection $section): array
    {
        try {
            $property = new \ReflectionProperty($section, 'items');
            $property->setAccessible(true);

            $items = $property->getValue($section);

            if ($items instanceof Collection) {
                return $items->all();
            }

            return is_array($items) ? $items : [];
        } catch (\ReflectionException $e) {
            logger()->error(
                'WM-Package: impossibile leggere le voci della sezione di menu '
                ."«{$section->name}»: ".$e->getMessage()
            );

            return [];
        }
    }

    /**
     * Voci di menu del dominio trail_registry ("Catasto"), iniettate solo a
     * dominio acceso — a interruttore spento nessuna sezione Catasto deve
     * comparire.
     *
     * @return array<int, MenuItem>
     */
    protected function trailRegistryMenuItems(): array
    {
        return [
            MenuItem::resource(TrailApplication::class)->name(__('Istanze')),
            MenuItem::resource(TrailRegistryCode::class)->name(__('Registro dei codici')),
            MenuItem::resource(TrailRegistryAnomaly::class)->name(__('Anomalie')),
        ];
    }

    public function packageRegistered()
    {
        $this->registerEnabledDomains();

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

        // Register the correct import service for the ImportUgcMediaJob
        $this->app->when(ImportUgcMediaJob::class)
            ->needs(GeohubImportService::class)
            ->give(function () {
                return new UgcMediaImportService;
            });

        // Register the morphMap for polymorphic relationships
        Relation::morphMap([
            'App\Models\UgcPoi' => Models\UgcPoi::class,
            'App\Models\UgcTrack' => Models\UgcTrack::class,
            'App\Models\EcPoi' => Models\EcPoi::class,
            'App\Models\EcTrack' => Models\EcTrack::class,
            'App\Models\Layer' => Models\Layer::class,
            'App\Models\App' => AppModel::class,
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
        // SOLUZIONE 1 - SEMPLICE: LE CONFIG DEI LOG BENGONO SOVRASCRITTE DOPO LA REGISRTAZIONE DEL PROVIDER
        if (isset($this->app->config['logging'])) {
            $wmLogging = config('wm-logging', []);
            $appChannels = $this->app->config['logging']['channels'] ?? [];
            $wmChannels = $wmLogging['channels'] ?? [];
            $merged = array_merge($this->app->config['logging'], $wmLogging);
            // Deep merge channels so app-defined channels are not lost
            $merged['channels'] = array_merge($wmChannels, $appChannels);
            $this->app->config['logging'] = $merged;
        }
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

    protected function addWmpackageToolsMenuItem()
    {
        $createHorizonMenuItem = function () {
            $menuItem = MenuItem::externalLink(__('Horizon'), url('/horizon'))
                ->canSee(fn () => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };
        $createDownloadDbMenuItem = function () {
            $menuItem = MenuItem::externalLink(__('Download DB'), route('download.db'))
                ->canSee(fn () => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };
        $createRestoreDbMenuItem = function () {
            // Create a menu item that opens the restore confirmation page
            // Only show in non-production environments
            $menuItem = MenuItem::externalLink(__('Restore DB'), route('restore.db.show'))
                ->canSee(fn () => ! App::environment('production') && optional(Auth::user())->hasRole('Administrator'))
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
                ->canSee(fn () => optional(Auth::user())->hasRole('Administrator'))
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
                ->canSee(fn () => optional(Auth::user())->hasRole('Administrator'))
                ->openInNewTab();

            return $menuItem;
        };

        if (Nova::$mainMenuCallback) {
            $originalCallback = Nova::$mainMenuCallback;

            Nova::mainMenu(function (Request $request) use ($originalCallback, $createDownloadDbMenuItem, $createRestoreDbMenuItem, $createMinioMenuItem, $createHorizonMenuItem, $createKibanaMenuItem) {
                $menuItems = call_user_func($originalCallback, $request);
                $downloadDbMenuItem = $createDownloadDbMenuItem();
                $restoreDbMenuItem = $createRestoreDbMenuItem();
                $minioMenuItem = $createMinioMenuItem();
                $horizonMenuItem = $createHorizonMenuItem();
                $kibanaMenuItem = $createKibanaMenuItem();

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
                $toolsItems[] = $restoreDbMenuItem;

                $menuItems = $this->injectMenuSectionItems($menuItems, __('Tools'), $toolsItems, 'briefcase');

                if (FeaturesService::isEnabled('trail_registry')) {
                    $menuItems = $this->injectMenuSectionItems($menuItems, __('Catasto'), $this->trailRegistryMenuItems(), 'map');
                }

                return $menuItems;
            });
        } else {
            Nova::mainMenu(function (Request $request) use ($createDownloadDbMenuItem, $createRestoreDbMenuItem, $createMinioMenuItem, $createHorizonMenuItem, $createKibanaMenuItem) {
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
                $toolsItems[] = $createRestoreDbMenuItem();

                // Chiuse di default: e' il menu di un pannello che ha gia'
                // parecchie sezioni, e aprirle tutte all'ingresso costringe a
                // scorrere per trovare quella che serve.
                $menuItems = [
                    MenuSection::make(__('Tools'), $toolsItems)
                        ->icon('color-swatch')
                        ->collapsedByDefault(),
                ];

                if (FeaturesService::isEnabled('trail_registry')) {
                    $menuItems[] = MenuSection::make(__('Catasto'), $this->trailRegistryMenuItems())
                        ->icon('map')
                        ->collapsedByDefault();
                }

                return $menuItems;
            });
        }
    }
}
