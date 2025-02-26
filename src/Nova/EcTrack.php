<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Traits\UgcTrait;

class EcTrack extends AbstractMultiLineStringModel
{

    public static $model = \Wm\WmPackage\Models\EcTrack::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
        ];
    }
}
