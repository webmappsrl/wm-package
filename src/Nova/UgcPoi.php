<?php

namespace Wm\WmPackage\Nova;

use Wm\WmPackage\Traits\UgcTrait;
use Wm\WmPackage\Nova\AbstractPointModel;
use Laravel\Nova\Http\Requests\NovaRequest;

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
