<?php

namespace Wm\WmPackage\Nova;

use Illuminate\Support\Collection;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Marshmallow\Tiptap\Tiptap;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Nova\Actions\DownloadEcTrackAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\ReverseTrackDirectionAction;
use Wm\WmPackage\Nova\Actions\TranslateModelAction;
use Wm\WmPackage\Nova\Actions\UploadTrackFile;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Nova\Fields\TrackColor\src\TrackColor;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Nova\Filters\FeaturesExcludeByIds;
use Wm\WmPackage\Nova\Filters\FeaturesIncludeByIds;
use Wm\WmPackage\Nova\Traits\DispatchesDemOnDetail;
use Wm\WmPackage\Nova\Traits\HasConfigDetailPanel;
use Wm\WmPackage\Nova\Traits\MultiLinestringResourceTrait;
use Wm\WmPackage\Services\LayerMapWebComponentService;

/**
 * @mixin \Wm\WmPackage\Models\EcTrack
 *
 * @property \Wm\WmPackage\Models\EcTrack $resource
 */
class EcTrack extends AbstractEcResource
{
    use DispatchesDemOnDetail;
    use HasConfigDetailPanel;
    use MultiLinestringResourceTrait {
        fields as protected fieldsTrait;
    }

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function singularLabel(): string
    {
        return __('EC Track');
    }

    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public static $title = 'name';

    public function title()
    {
        return $this->getTranslation('name', 'it');
    }

    public function fields(NovaRequest $request): array
    {
        $this->dispatchDemOnDetail($request);

        return [
            ...$this->fieldsTrait($request),
            // Le tassonomie del modello unico, sopra i Blocchi Dettaglio: valgono per ogni
            // shard, quindi la sezione la compone il package e lo shard la eredita. Chi vuole
            // aggiungerci campi propri usa `taxonomyTabExtraFields()` (oc:8910).
            ...$this->taxonomySectionFields(),
            $this->configDetailPanel(),
            Tab::group(__('Details'), [
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('Style'), $this->getStyleTabFields()),
                Tab::make(__('DEM'), $this->getDemTabFields()),
            ]),
            $this->webComponentField($request),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)
                ->searchable()
                ->collapsedByDefault(),
            MorphToMany::make('Layers', 'layers', Layer::class)
                ->collapsedByDefault(),
            MorphToMany::make('Activities', 'taxonomyActivities', TaxonomyActivity::class)
                ->display('name')
                ->collapsedByDefault(),
        ];
    }

    /**
     * Bottone «Copia codice web component» della tappa: lo snippet del cammino con `track-id`,
     * che apre la mappa con la tappa già selezionata (oc:8944). Con più layer proponibili compare
     * una select per scegliere quale.
     */
    protected function webComponentField(NovaRequest $request): Text
    {
        $service = app(LayerMapWebComponentService::class);
        $trackId = $this->resource->id;

        // Calcolati una volta e solo sulla detail view: su edit e update Nova risolve e autorizza
        // i Field prima di scartare quelli onlyOnDetail, e la query sui layer sarebbe buttata.
        $layers = null;
        $resolveLayers = function () use (&$layers, $request): Collection {
            return $layers ??= $this->resource->exists ? $this->webComponentLayers($request) : collect();
        };

        return Text::make(__('Web Component'), function () use ($resolveLayers, $service, $trackId) {
            return $service->renderCopyButton($resolveLayers()->map(fn ($layer) => [
                'label' => $layer->getStringName().' (#'.$layer->id.')',
                'snippet' => $service->snippet($layer, $trackId),
            ])->all());
        })
            ->asHtml()
            ->onlyOnDetail()
            ->canSee(fn () => $request->isResourceDetailRequest() && $resolveLayers()->isNotEmpty());
    }

    /**
     * I layer proponibili nel bottone del web component: quelli della traccia la cui App ha il
     * flag acceso, ordinati per id così l'option preselezionata è la stessa a ogni caricamento.
     * Un consumer lo sovrascrive per restringerli (es. ai layer dell'utente), partendo da
     * `parent::` per non perdere il filtro sul flag.
     *
     * @return Collection<int, \Wm\WmPackage\Models\Layer>
     */
    protected function webComponentLayers(NovaRequest $request): Collection
    {
        $service = app(LayerMapWebComponentService::class);

        return $this->resource->layers()
            ->with('appOwner')
            ->orderBy('layers.id')
            ->get()
            ->filter(fn ($layer) => $layer instanceof \Wm\WmPackage\Models\Layer && $service->isEnabledFor($layer))
            ->values()
            ->toBase();
    }

    public function filters(NovaRequest $request): array
    {
        // Needed filters for the custom field on layers
        if (str_contains($request->getUri(), '/nova-api/')) {
            $layerRelationName = $this->resource->getLayerRelationName();

            return [
                new FeaturesByLayerFilter($layerRelationName),
                new FeaturesIncludeByIds($layerRelationName),
                new FeaturesExcludeByIds($layerRelationName),
            ];
        }

        return [];
    }

    /**
     * Get the actions available for the resource.
     */
    public function actions(NovaRequest $request): array
    {
        // Operazione non annullabile dall'interfaccia (inverte il verso della traccia): ristretta
        // al ruolo Administrator (oc:8543).
        $administratorOnly = fn (NovaRequest $request) => optional($request->user())->hasRole('Administrator');

        return [
            new Actions\ReindexSearchableAction,
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Update Tracks on AWS')),
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new SyncModelTaxonomyWhereJob($ecTrack),
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Regenerate Taxonomy Where')),
            new ExecuteEcTrackDataChainAction,
            (new ReverseTrackDirectionAction)
                ->sole()
                ->canSee($administratorOnly)
                ->canRun($administratorOnly),
            new DownloadEcTrackAction,
            (new UploadTrackFile)->standalone(),
            new TranslateModelAction,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        return [
            new EcTrackApiLinksCard($request->findModelOrFail()),
        ];
    }

    public function getInfoTabFields(): array
    {
        return [
            NovaTabTranslatable::make([
                Tiptap::make(__('Description'), 'properties->description')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
            ]),
            Boolean::make('Not Accessible', 'properties->not_accessible')
                ->help('Enable this option to indicate that the track is not accessible. The reason can be specified below.'),
            NovaTabTranslatable::make([
                Textarea::make(__('Not Accessible Message'), 'properties->not_accessible_message'),
            ]),
            Text::make(__('QR Code'), 'deep_link_qr_code', function () {
                return $this->app?->renderDeepLinkQrCodeHtml('track', $this->id);
            })
                ->asHtml()
                ->onlyOnDetail()
                ->canSee(fn () => optional($this->resource->app)->isNativeAppDeepLinkEnabled() ?? false),

        ];
    }

    protected function getStyleTabFields(): array
    {
        return [
            TrackColor::make(__('Color'), 'properties->color')->hideFromIndex(),
        ];
    }
}
