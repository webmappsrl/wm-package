<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Services\GeometryComputationService;

class UgcObserver extends AbstractAuthorableObserver
{
    public function creating(Model $model)
    {
        $model->geometry = app(GeometryComputationService::class)->convertTo3DGeometry($model->geometry);
    }
}
