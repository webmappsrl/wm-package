<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Traits\PointResourceTrait;

class EcPoi extends AbstractEcResource
{
    use PointResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $model = \Wm\WmPackage\Models\EcPoi::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class),
        ];
    }
}
