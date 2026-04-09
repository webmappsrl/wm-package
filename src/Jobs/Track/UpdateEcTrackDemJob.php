<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\Models\EcTrackService;

class UpdateEcTrackDemJob extends BaseEcTrackJob
{
    public $queue = 'dem';

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(EcTrackService $ecTrackService)
    {
        $ecTrackService->updateDemData($this->ecTrack);
    }
}
