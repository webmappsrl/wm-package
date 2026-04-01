<?php

namespace Wm\WmPackage\Nova;

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
}
