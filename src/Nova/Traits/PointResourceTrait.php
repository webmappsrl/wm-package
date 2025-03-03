<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\MapPoint\MapPoint;

trait PointResourceTrait
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MapPoint::make('Geometry', 'geometry')->hideFromIndex(),
        ];
    }
}
