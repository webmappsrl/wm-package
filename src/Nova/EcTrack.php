<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Traits\MultiLinestringResourceTrait;

class EcTrack extends AbstractEcResource
{
    use MultiLinestringResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...$this->fieldsTrait($request),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class),
        ];
    }
}
