<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\GeometryComputationService;

class UpdateEcTrackSlopeValues extends BaseEcTrackJob
{

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(GeometryComputationService $geometryComputationService)
    {
        $geojson = $this->ecTrack->getTrackGeometryGeojson();
        $trackSlope = $geometryComputationService->calculateSlopeValues($geojson);
        if (! is_null($trackSlope)) {
            $this->ecTrack->fill(['slope' => $trackSlope])->saveQuietly();
        }
    }
}
