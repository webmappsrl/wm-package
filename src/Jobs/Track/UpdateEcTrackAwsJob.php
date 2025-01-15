<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\CloudStorageService;

class UpdateEcTrackAwsJob extends BaseEcTrackJob
{

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(CloudStorageService $cloudStorageService)
    {
        $geojson = $this->ecTrack->getGeojson();
        $trackUri = $this->ecTrack->id . '.json';
        $cloudStorageService->storeTrack($trackUri, json_encode($geojson));
    }
}
