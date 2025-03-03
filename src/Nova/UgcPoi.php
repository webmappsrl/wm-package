<?php

namespace Wm\WmPackage\Nova;

use Wm\WmPackage\Nova\Traits\PointResourceTrait;

class UgcPoi extends AbstractUgcResource
{
    use PointResourceTrait;

    public static $model = \Wm\WmPackage\Models\UgcPoi::class;
}
