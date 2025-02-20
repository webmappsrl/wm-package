<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\MapPoint\MapPoint;

abstract class AbstractPointModel extends AbstractGeometryModel
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MapPoint::make('Geometry', 'geometry')->hideFromIndex(),
        ];
    }
}
