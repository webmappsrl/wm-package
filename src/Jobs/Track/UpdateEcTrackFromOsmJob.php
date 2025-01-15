<?php

namespace Wm\WmPackage\Jobs\Track;

use Exception;
use Wm\WmPackage\Services\EcTrackService;


class UpdateEcTrackFromOsmJob extends BaseEcTrackJob
{

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(EcTrackService $ecTrackService)
    {

        $result = $ecTrackService->updateOsmData($this->ecTrack);
        if (! $result['success']) {
            throw new Exception($this->ecTrack->id . ' UpdateTrackFromOsmJob: FAILED: ' . $this->ecTrack->name . ' (' . $this->ecTrack->osmid . '): ' . $result['message']);
        }
    }
}
