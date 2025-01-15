<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\EcTrackService;

class UpdateEcTrackManualDataJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(EcTrackService $ecTrackService)
    {
        $ecTrackService->updateManualData($this->ecTrack);
    }
}
