<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\ExportTracksFeatureCollectionAction;
use Wm\WmPackage\Nova\Traits\MultiLinestringResourceTrait;

class UgcTrack extends AbstractUgcResource
{
    use MultiLinestringResourceTrait;

    public static $model = \Wm\WmPackage\Models\UgcTrack::class;

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function singularLabel(): string
    {
        return __('UGC Track');
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ExportTracksFeatureCollectionAction,
        ];
    }
}
