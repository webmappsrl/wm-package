<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Services\GeometryComputationService;

class UpdateEcTrack3DDemJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(DemClient $demClient, GeometryComputationService $geometryComputationService)
    {
        $geojson = $this->ecTrack->getGeojson();
        $responseData = $demClient->getTechData($geojson);

        // TODO: here we can set in the geometry a raw expression to execute only 1 query
        $this->ecTrack->geometry = $geometryComputationService->getWktFromGeojson(json_encode($responseData['geometry']));
        $this->ecTrack->saveQuietly();
    }
}
