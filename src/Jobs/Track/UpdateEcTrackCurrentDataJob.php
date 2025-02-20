<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\Models\EcTrackService;

class UpdateEcTrackCurrentDataJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(EcTrackService $ecTrackService)
    {
        $ecTrackService->updateCurrentData($this->ecTrack);
    }
}
