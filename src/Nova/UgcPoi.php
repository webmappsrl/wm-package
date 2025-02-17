<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Traits\UgcTrait;

class UgcPoi extends AbstractPointModel
{
    use UgcTrait;

    public static $model = \Wm\WmPackage\Models\UgcPoi::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            ...$this->ugcNovaFields($request),
        ];
    }
}
