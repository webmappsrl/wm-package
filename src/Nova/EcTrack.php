<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\MorphMany;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Filters\FeaturesExcludeByIds;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Nova\Filters\FeaturesIncludeByIds;

use Wm\WmPackage\Nova\Traits\MultiLinestringResourceTrait;

class EcTrack extends AbstractEcResource
{
    use MultiLinestringResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class),
            MorphMany::make('Layers', 'layers', Layer::class),
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
}
