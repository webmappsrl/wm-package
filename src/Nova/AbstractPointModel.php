<?php

namespace Wm\WmPackage\Nova;

use Wm\MapPoint\MapPoint;
use Laravel\Nova\Http\Requests\NovaRequest;

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
