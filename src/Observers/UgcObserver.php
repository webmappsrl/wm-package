<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

class UgcObserver extends AbstractAuthorableObserver
{
    public function creating(Model $model)
    {
        parent::creating($model);
        $this->normalizeGeometry($model);
        $model->name = $model->name ?? $model->properties['name'];
    }

    private function normalizeGeometry(Model $model)
    {
        $service = app(GeometryComputationService::class);
        $model->geometry = $service->convertTo3DGeometry($model->geometry);
        if ($model instanceof UgcTrack) {
            if ($service->isGeometryLinestring($model)) {
                $model->geometry = $service->getMultilinestringFromLinestring($model); //DB is expecting a MultiLineString in ugc_tracks
            }
        }
    }
}
