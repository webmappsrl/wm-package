<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterPoiTaxonomyChangeJob;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterTrackTaxonomyChangeJob;
use Wm\WmPackage\Models\Layer as LayerModel;
use Wm\WmPackage\Models\TaxonomyWhere as TaxonomyWhereModel;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\LayerApiLinksCard;
use Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard;
use Wm\WmPackage\Nova\Concerns\BuildsTaxonomyFields;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Nova\Fields\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Nova\Traits\HasConfigDetailPanel;
use Wm\WmPackage\Nova\Traits\MultiPolygonResourceTrait;
use Wm\WmPackage\Services\LayerMapWebComponentService;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

class Layer extends AbstractGeometryResource
{
    use BuildsTaxonomyFields {
        legacyTaxonomies as protected packageLegacyTaxonomies;
    }
    use HasConfigDetailPanel;
    use MultiPolygonResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $with = ['ecTracks', 'ecPois', 'appOwner', 'associatedApps'];

    /**
     * Build an "index" query for the given resource.
     *
     * Scopes by app_id for any non-Administrator, same criterion as
     * AbstractEcResource::indexQuery() (Editor and Validator both scoped —
     * unlike UGC, which bypasses Validator entirely).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            $table = $query->getModel()->getTable();
            if (Schema::hasColumn($table, 'app_id')) {
                return $query->whereIn('app_id', $user->ownedAppIds());
            }
        }

        return $query;
    }

    public static $model = LayerModel::class;

    public static $title = 'name';

    public static $search = [
        'id',
        'name',
    ];

    /**
     * Su un layer i termini scelgono i contenuti, non li descrivono: scelta sempre multipla e
     * nessuna dipendenza fra tassonomie (oc:8910).
     */
    protected function taxonomyFieldsAreFilters(): bool
    {
        return true;
    }

    /**
     * Cambiati i filtri, il layer va ripopolato subito (oc:8910).
     *
     * `LayerObserver` non se ne accorgerebbe: i termini si scrivono dopo il salvataggio del
     * modello, quindi il layer non risulta cambiato. I job sono quelli che già girano quando un
     * contenuto cambia tassonomia — riassegnano i contenuti, riallineano `properties.layers`,
     * rigenerano i PBF e fanno reindicizzare: in coda, perché su un layer grande non è lavoro da
     * richiesta.
     *
     * Le due modalità si guardano separatamente: un layer può avere le tracce in automatico e i
     * POI a mano, o il contrario. Guardando solo `isAutoTrackMode()`, un layer con i soli POI in
     * automatico non faceva partire niente, e i suoi POI restavano quelli di prima a tempo
     * indeterminato.
     */
    protected function afterTaxonomyFieldsSaved(mixed $model): void
    {
        if (! $model instanceof LayerModel) {
            return;
        }

        if ($model->isAutoTrackMode()) {
            SyncAutoLayerAfterTrackTaxonomyChangeJob::dispatch($model->id);
        }

        if ($model->isAutoPoiMode()) {
            SyncAutoLayerAfterPoiTaxonomyChangeJob::dispatch($model->id);
        }
    }

    /**
     * Le zone sono un filtro del layer come gli altri (oc:8910).
     *
     * `LayerService` le tratta già allo stesso modo di attività e termini — in AND con loro,
     * «i cammini che attraversano questa zona» — ma il campo stava fuori dalla sezione, e la
     * frase che spiega la regola non le copriva. Qui diventano una tassonomia storica come le
     * attività: stessa select multipla, stesso tab di default, stesso salvataggio.
     *
     * Vale solo sul layer. Sulla scheda di un contenuto le zone restano in sola lettura, perché
     * lì sono calcolate dalla geometria: su un layer sono una scelta.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function legacyTaxonomies(): array
    {
        return [
            ...$this->packageLegacyTaxonomies(),
            [
                'key' => 'wheres',
                'relation' => 'taxonomyWheres',
                'model' => TaxonomyWhereModel::class,
                'label' => 'Zones',
                'tab' => 'General',
                'single_choice' => false,
                'hidden' => [],
            ],
        ];
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Boolean::make('In Home', function () {
                /** @var LayerModel $layer */
                $layer = $this->resource;

                if (! $layer->app_id) {
                    return false;
                }

                $app = $layer->appOwner;
                if (! $app) {
                    return false;
                }

                $raw = $app->getRawOriginal('config_home');
                if (empty($raw)) {
                    return false;
                }

                $data = json_decode($raw, true);
                $home = $data['HOME'] ?? [];

                return collect($home)->contains(
                    fn ($item) => ($item['box_type'] ?? '') === 'layer'
                        && (int) ($item['layer'] ?? 0) === $layer->id
                );
            })->onlyOnIndex(),
            NovaTabTranslatable::make([
                Text::make(__('Name'), 'name')->required(),
            ]),
            Number::make(__('Rank'), 'rank', function () {
                if (is_array($this->properties) && isset($this->properties['rank'])) {
                    return (int) $this->properties['rank'];
                }

                return $this->rank ?? 0;
            })->onlyOnIndex()->sortable(),
            BelongsTo::make(__('App'), 'appOwner', App::class),
            BelongsTo::make(__('Owner'), 'layerOwner', User::class)
                ->nullable()
                ->searchable(),
            Images::make(__('Image'), 'default'),
            Images::make(__('Logo'), 'logo')
                ->singleMediaRules(['mimes:png,webp', 'dimensions:ratio=1/1']),
            PropertiesPanel::makeWithModel(__('Properties'), 'properties', $this, true)->collapsible(),
            $this->configDetailPanel(),
            // Le attività non hanno più un pannello a sé: sono un filtro come gli altri, dentro
            // la sezione qui sotto.
            // I filtri per tassonomia, organizzati come nella scheda di un contenuto (oc:8910).
            // Qui però scelgono: una traccia entra nel layer se soddisfa tutti i filtri
            // impostati, e dentro un filtro le basta uno dei valori scelti.
            $this->makeTaxonomySection(__('Filters')),
            Panel::make(__('Map'), [
                FeatureCollectionMap::make(__('Geometry'), 'geometry')
                    ->onlyOnDetail()
                    // Senza PostHog non ci sono posizioni da mostrare: niente marker e niente
                    // legenda che li annuncia (oc:8910).
                    ->withMeta(['showLiveUserPositions' => app(AnalyticsService::class)->isConfigured()]),
            ]),
            Panel::make('Ec Tracks', [
                LayerFeatures::make(__('tracks'), $this->resource, config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack'))
                    ->hideWhenCreating()
                    ->withMeta(['model_class' => config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack')]),
            ]),
            Panel::make('Ec Pois', [
                LayerFeatures::make(__('pois'), $this->resource, config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi'))
                    ->hideWhenCreating()
                    ->withMeta(['model_class' => config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi')]),
            ]),
            // Snippet e bottone sono condivisi con la Resource EcTrack (oc:8944).
            Text::make(__('Web Component'), function () {
                /** @var LayerModel $layer */
                $layer = $this->resource;
                $webComponent = app(LayerMapWebComponentService::class);

                return $webComponent->renderCopyButton([
                    ['label' => $layer->getStringName(), 'snippet' => $webComponent->snippet($layer)],
                ]);
            })
                ->asHtml()
                ->onlyOnDetail()
                ->canSee(function () {
                    /** @var LayerModel $layer */
                    $layer = $this->resource;

                    return app(LayerMapWebComponentService::class)->isEnabledFor($layer);
                }),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new AddLayersToConfigHomeAction,
            new Actions\RegenerateLayerPbfAction,
            ExecuteEcTrackDataChainAction::make()
                ->confirmText(__('Are you sure you want to process all tracks of this layer?'))
                ->confirmButtonText(__('Yes, process'))
                ->cancelButtonText(__('No, cancel')),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new AppFilter,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if ($request->resourceId) {
            return $this->detailCards($request);
        }

        return $this->indexCards($request);
    }

    protected function detailCards(NovaRequest $request): array
    {
        /** @var LayerModel $layer */
        $layer = $request->findModelOrFail();
        $app = $layer->appOwner;
        $appProperties = $this->getLayerAppProperties($layer);
        $cards = [new LayerApiLinksCard($layer)];
        $analyticsEnabled = $app &&
            (($appProperties['analytics_app_enabled'] ?? false) ||
             ($appProperties['analytics_webapp_enabled'] ?? false));

        if ($analyticsEnabled) {
            $cards[] = new LayerAnalyticsCard($layer);
        }

        return $cards;
    }

    protected function indexCards(NovaRequest $request): array
    {
        if (! $this->canSeeGlobalAnalyticsCard($request)) {
            return [];
        }

        /** @var LayerModel|null $anyLayer */
        $anyLayer = static::newModel()->query()->first();
        if (! $anyLayer) {
            return [];
        }

        $app = $anyLayer->appOwner;
        $appProperties = $this->getLayerAppProperties($anyLayer);
        $analyticsEnabled = $app &&
            (($appProperties['analytics_app_enabled'] ?? false) ||
             ($appProperties['analytics_webapp_enabled'] ?? false));

        return $analyticsEnabled ? [LayerAnalyticsCard::global()] : [];
    }

    /**
     * Consumers with project-specific roles (e.g. Administrator-only dashboards)
     * should override this to restrict the global analytics card on the Layer index.
     */
    protected function canSeeGlobalAnalyticsCard(NovaRequest $request): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function getLayerAppProperties(LayerModel $layer): array
    {
        $app = $layer->appOwner;

        return $app ? ($app->properties ?? []) : [];
    }
}
