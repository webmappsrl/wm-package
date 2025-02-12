<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Resource;
use Laravel\Nova\Fields\Code;
use Wm\WmPackage\Nova\AbstractUgc;
use Laravel\Nova\Http\Requests\NovaRequest;

class UgcPoi extends AbstractUgc
{
    public static $model = \Wm\WmPackage\Models\UgcPoi::class;
}
