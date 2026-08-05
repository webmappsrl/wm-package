<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Services\GeometryComputationService;

class ImportUgcPoiJob extends BaseUgcImportJob
{
    protected function getModelKey(): string
    {
        return 'ugc_poi';
    }

    protected function fillGeometry($rawGeometry)
    {
        if (empty($rawGeometry)) {
            return null;
        }

        return app(GeometryComputationService::class)->convertTo3DGeometry($rawGeometry);
    }

    protected function processDependencies(array $data, Model $model): void
    {
        //
    }
}
