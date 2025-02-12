<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Code;
use Laravel\Nova\Http\Requests\NovaRequest;

class UgcTrack extends AbstractUgc
{
    public static $model = \Wm\WmPackage\Models\UgcTrack::class;
}
