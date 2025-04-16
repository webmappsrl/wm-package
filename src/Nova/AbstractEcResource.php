<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Laravel\Nova\Http\Requests\NovaRequest;
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

    /**
     * Determine if this resource uses Laravel Scout.
     * https://nova.laravel.com/docs/v5/search/scout-integration
     *
     * @return bool
     */
    public static function usesScout()
    {
        return false;
    }
}
