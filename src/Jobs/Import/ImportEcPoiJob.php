<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ImportEcPoiJob extends BaseImportJob
{
    protected function getModelKey(): string
    {
        return 'ec_poi';
    }

    protected function transformData(array $data): array
    {
        $transformedData = parent::transformData($data);

        // if geometry is null set a default geometry
        if (empty($transformedData['geometry'])) {
            $transformedData['geometry'] = DB::raw("ST_GeomFromText('POINT Z(12.4964 41.9028 0)')");
        } else {
            $transformedData['geometry'] = $this->forceTo3DGeometry($transformedData['geometry']);
        }

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        // no dependencies handled in ec poi job
    }
}
