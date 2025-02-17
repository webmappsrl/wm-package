<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;

abstract class AbstractMultiLineStringModel extends AbstractGeometryModel
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            // MapMultiLinestring::make('Geometry', 'geometry'), TODO: uncomment when geometry field issue with nova 5 is fixed
        ];
    }
}
