<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Traits\EcTrait;

class EcPoi extends AbstractPointModel
{
    use EcTrait;

    public static $model = \Wm\WmPackage\Models\EcPoi::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            ...$this->ecNovaFields($request),
        ];
    }
}
