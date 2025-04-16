<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Http\Resources\EcTrackResource;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\StorageService;

class UpdateEcTrackAwsJob extends BaseEcTrackJob
{
    public function __construct(protected EcTrack $ecTrack)
    {
        parent::__construct($ecTrack);
        $this->onQueue('aws');
    }

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
