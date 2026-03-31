<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

trait MultiLinestringResourceTrait
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            FeatureCollectionMap::make('Geometry', 'geometry')
                ->enableSlopeChart(true)
                ->hideFromIndex()
                ->required(),
        ];
    }
}
