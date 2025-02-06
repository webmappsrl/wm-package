<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\StorageService;

class UpdateEcTrackAwsJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(StorageService $cloudStorageService)
    {
        $geojson = $this->ecTrack->getGeojson();
        $cloudStorageService->storeTrack($this->ecTrack->id, json_encode($geojson));
    }
}
