<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Traits\UgcTrait;

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
