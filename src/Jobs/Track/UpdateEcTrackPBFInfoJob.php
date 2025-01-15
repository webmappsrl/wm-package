<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\EcTrackService;

class UpdateEcTrackPBFInfoJob extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(EcTrackService $ecTrackService)
    {
        $ecTrackService->updateTrackPBFInfo($this->ecTrack);
    }
}
