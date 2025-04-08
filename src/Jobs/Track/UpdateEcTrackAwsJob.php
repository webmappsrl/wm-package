<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\StorageService;

class UpdateEcTrackAwsJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(StorageService $cloudStorageService, GeometryComputationService $geometryComputationService)
    {
        $geojson = $this->ecTrack->getGeojson();

        // force linestring
        $geometryLinestring = $geometryComputationService->get3dLineMergeGeojsonFromGeojson(json_encode($geojson));
        $geojson['geometry'] = json_decode($geometryLinestring, true)['geometry'];

        // save on aws
        $cloudStorageService->storeTrack($this->ecTrack->id, json_encode($geojson));
    }
}
