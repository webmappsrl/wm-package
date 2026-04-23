<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\LayerApiLinksCard;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Nova\Fields\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Nova\Traits\MultiPolygonResourceTrait;

class Layer extends AbstractGeometryResource
{
    use MultiPolygonResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $with = ['ecTracks', 'manualEcPois', 'appOwner', 'associatedApps'];

    public static $model = \Wm\WmPackage\Models\Layer::class;

    public static $title = 'name';

    public static $search = [
        'id',
        'name',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Boolean::make('In Home', function () {
                /** @var \Wm\WmPackage\Models\Layer $layer */
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
            PropertiesPanel::makeWithModel(__('Properties'), 'properties', $this, true)->collapsible(),
            MorphToMany::make(__('Activities'), 'taxonomyActivities', TaxonomyActivity::class),
            MorphToMany::make('Taxonomy Where', 'taxonomyWheres', TaxonomyWhere::class)
                ->actions(fn () => []),
            Panel::make('Ec Tracks', [
                FeatureCollectionMap::make(__('Geometry'), 'geometry')->onlyOnDetail(),
                LayerFeatures::make(__('tracks'), $this->resource, config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack'))
                    ->hideWhenCreating()
                    ->withMeta(['model_class' => config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack')]),
            ]),
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
        if (! $request->resourceId) {
            return [];
        }

        return [
            new LayerApiLinksCard($request->findModelOrFail()),
        ];
    }
}
