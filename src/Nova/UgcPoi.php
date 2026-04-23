<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\ConvertUgcPoiToEcPoi;
use Wm\WmPackage\Nova\Filters\ShareUgcPoiFilter;
use Wm\WmPackage\Nova\Traits\PointResourceTrait;

class UgcPoi extends AbstractUgcResource
{
    use PointResourceTrait;

    public static $model = \Wm\WmPackage\Models\UgcPoi::class;

    public static function label(): string
    {
        return __('Pois');
    }

    public static function singularLabel(): string
    {
        return __('UGC Poi');
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ConvertUgcPoiToEcPoi,
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            (new ShareUgcPoiFilter),
        ];
    }
}
