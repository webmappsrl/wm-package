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
        $geojson = $this->ecTrack->getGeojson();
        $trackSlope = $geometryComputationService->calculateSlopeValues($geojson);
        if (! is_null($trackSlope)) {
            $properties = $this->ecTrack->properties ?? [];
            $properties['slope'] = $trackSlope;
            $this->ecTrack->properties = $properties;
            $this->ecTrack->saveQuietly();
        }
    }
}
