<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\MapMultiLinestring\MapMultiLinestring;

abstract class AbstractMultiLineStringModel extends AbstractGeometryModel
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MapMultiLinestring::make('Geometry', 'geometry')->hideFromIndex(),
        ];
    }
}
