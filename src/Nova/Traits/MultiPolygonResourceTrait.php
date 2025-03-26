<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\MapMultiPolygon\MapMultiPolygon;

trait MultiPolygonResourceTrait
{
    public function fields(NovaRequest $request): array
    {
        return [
            // MapMultiPolygon::make('Geometry', 'geometry')->hideFromIndex()->readonly(),
        ];
    }
}
