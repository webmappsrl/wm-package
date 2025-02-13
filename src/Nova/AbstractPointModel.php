<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractGeometryModel;

abstract class AbstractPointModel extends AbstractGeometryModel
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            //MapPoint::make('Geometry', 'geometry'), TODO: uncomment when geometry field issue with nova 5 is fixed
        ];
    }
}
