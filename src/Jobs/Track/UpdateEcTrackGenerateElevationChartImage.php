<?php

namespace Wm\WmPackage\Jobs\Track;

use Exception;
use Wm\WmPackage\Services\NodeJsService;

class UpdateEcTrackGenerateElevationChartImage extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(NodeJsService $nodeJsService)
    {
        $geojson = $this->ecTrack->getGeojson();
        if (! isset($geojson['properties']['id'])) {
            throw new Exception('The geojson id is not defined');
        }

        $path = $nodeJsService->generateElevationChartImage($geojson);
        $this->ecTrack->elevation_chart_image = $path;
        $this->ecTrack->saveQuietly();
    }
}
