<?php

namespace Wm\WmPackage\Nova;

use Wm\WmPackage\Traits\UgcTrait;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractMultiLineStringModel;

class UgcTrack extends AbstractMultiLineStringModel
{
    use UgcTrait;

    public static $model = \Wm\WmPackage\Models\UgcTrack::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            ...$this->ugcNovaFields($request),
        ];
    }
}
