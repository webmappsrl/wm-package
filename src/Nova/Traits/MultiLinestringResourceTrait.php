<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\MapMultiLinestring\MapMultiLinestring;

trait MultiLinestringResourceTrait
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MapMultiLinestring::make('Geometry', 'geometry')->withMeta(['tiles' => 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png'])->hideFromIndex()->required(),
        ];
    }
}
