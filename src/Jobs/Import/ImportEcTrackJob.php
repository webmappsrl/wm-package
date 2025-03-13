<?php

namespace Wm\WmPackage\Jobs\Import;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wm\WmPackage\Facades\WmLogger;

class ImportEcTrackJob extends BaseImportJob
{

    protected function getModelKey(): string
    {
        return 'ec_track';
    }

    protected function transformData(array $data): array
    {
        $transformedData = parent::transformData($data);


        //if geometry is null set a default geometry
        if (empty($transformedData['geometry'])) {
            $transformedData['geometry'] = DB::raw("ST_GeomFromText('LINESTRING(12.4964 41.9028, 12.5033 41.9019, 12.5092 41.9101, 12.4964 41.9028)')");
        } else {
            //transform geometry to 2D https://orchestrator.maphub.it/resources/developer-stories/5129
            $transformedData['geometry'] = DB::selectOne('SELECT ST_Force2D(?) as geometry', [$transformedData['geometry']])->geometry;
        }

        return $transformedData;
    }


    protected function processDependencies(array $data, Model $model): void
    {
        $ecPoiIds = $this->geohubImportService->getAssociatedEcPoisIDs($this->getModelKey(), $data['id']);
        $model->ecPois()->sync($ecPoiIds);
    }
}
