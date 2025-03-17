<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ImportEcTrackJob extends BaseImportJob
{
    protected function getModelKey(): string
    {
        return 'ec_track';
    }

    protected function transformData(array $data): array
    {
        $transformedData = parent::transformData($data);

        // if geometry is null set a default 3D geometry
        if (empty($transformedData['geometry'])) {
            $transformedData['geometry'] = DB::raw("ST_GeomFromText('LINESTRING Z(12.4964 41.9028 0, 12.5033 41.9019 0, 12.5092 41.9101 0, 12.4964 41.9028 0)')");
        } else {
            $transformedData['geometry'] = $this->forceTo3DGeometry($transformedData['geometry']);
        }

        return $transformedData;
    }

    protected function processDependencies(array $data, Model $model): void
    {
        $ecPoiIds = $this->geohubImportService->getAssociatedEcPoisIDs($this->getModelKey(), $data['id']);
        $model->ecPois()->sync($ecPoiIds);
    }
}
