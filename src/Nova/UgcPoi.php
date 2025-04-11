<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Filters\ShareUgcPoiFilter;
use Wm\WmPackage\Nova\Traits\PointResourceTrait;
use Wm\WmPackage\Nova\Actions\ConvertUgcPoiToEcPoi;

class UgcPoi extends AbstractUgcResource
{
    use PointResourceTrait;

    public static $model = \Wm\WmPackage\Models\UgcPoi::class;


    public function actions(NovaRequest $request): array
    {
        return [
            new ConvertUgcPoiToEcPoi,

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            (new ShareUgcPoiFilter),
        ];
    }
}
