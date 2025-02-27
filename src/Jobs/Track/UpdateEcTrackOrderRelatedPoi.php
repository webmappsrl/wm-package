<?php

namespace Wm\WmPackage\Jobs\Track;

use Wm\WmPackage\Services\Models\EcTrackService;

class UpdateEcTrackOrderRelatedPoi extends BaseEcTrackJob
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(EcTrackService $ecTrackService)
    {

        $orderedPois = $ecTrackService->getRelatedPoisOrder($this->ecTrack);
        if (is_array($orderedPois) && count($orderedPois)) {
            $order = 1;
            foreach ($orderedPois as $poi_id) {
                $this->ecTrack->ecPois()->updateExistingPivot($poi_id, ['order' => $order]);
                $order++;
            }
        }
    }
}
