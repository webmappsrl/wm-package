<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Http\Resources\EcTrackResource;
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

        $resource = new EcTrackResource($this->ecTrack);

        // save on aws
        $cloudStorageService->storeTrack($this->ecTrack->id, $resource->toJson());
    }
}
