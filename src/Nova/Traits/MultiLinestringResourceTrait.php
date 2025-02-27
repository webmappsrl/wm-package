<?php

namespace Wm\WmPackage\Nova\Traits;

use Wm\MapMultiLinestring\MapMultiLinestring;
use Laravel\Nova\Http\Requests\NovaRequest;

trait MultiLinestringResourceTrait
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MapMultiLinestring::make('Geometry', 'geometry')->hideFromIndex(),
        ];
    }
}
