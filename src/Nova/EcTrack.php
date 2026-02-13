<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Nova\Filters\FeaturesExcludeByIds;
use Wm\WmPackage\Nova\Filters\FeaturesIncludeByIds;
use Wm\WmPackage\Nova\Traits\MultiLinestringResourceTrait;

class EcTrack extends AbstractEcResource
{
    use MultiLinestringResourceTrait {
        fields as protected fieldsTrait;
    }

    public static function label(): string
    {
        return __('Tracks');
    }

    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public static $title = 'name';

    public function title()
    {
        return $this->getTranslation('name', 'it');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)
                ->searchable(),
            MorphToMany::make('Layers', 'layers', Layer::class),
            MorphToMany::make('Activities', 'taxonomyActivities', TaxonomyActivity::class)->display('name'),
        ];
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
        return [
            new Actions\ReindexSearchableAction,
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Update Tracks on AWS')),
            new ExecuteEcTrackDataChainAction([
                fn ($ecTrack) => new UpdateModelWithGeometryTaxonomyWhere($ecTrack),
                fn ($ecTrack) => new UpdateEcTrackAwsJob($ecTrack),
            ], __('Regenerate Taxonomy Where')),
            new ExecuteEcTrackDataChainAction,
        ];
    }
}
