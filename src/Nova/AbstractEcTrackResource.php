<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;

class AbstractEcTrackResource extends AbstractEcResource
{
    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class),
        ];
    }
}
