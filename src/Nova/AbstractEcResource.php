<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;

abstract class AbstractEcResource extends AbstractGeometryResource
{

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Images::make('Image', 'default')->onlyOnDetail(),
        ];
    }
    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new FeaturesByLayerFilter($this->model()::class),
        ];
    }
}
