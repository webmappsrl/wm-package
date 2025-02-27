<?php

namespace Wm\WmPackage\Nova;

use Wm\WmPackage\Nova\EcTrack;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;

class AbstractEcPoiResource extends AbstractEcResource
{

    public static $model = \Wm\WmPackage\Models\EcPoi::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class)
        ];
    }
}
