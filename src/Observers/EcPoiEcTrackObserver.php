<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\EcPoiEcTrack;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class EcPoiEcTrackObserver
{
    /**
     * Handle the EcPoiEcTrack "created" event.
     * Triggered when a POI is attached to a track.
     *
     * @return void
     */
    public function created(EcPoiEcTrack $pivot)
    {
        $this->updateEcTrackDataChain($pivot);
    }

    /**
     * Handle the EcPoiEcTrack "updated" event.
     * Triggered when a pivot record is updated (e.g., order changed).
     *
     * @return void
     */
    public function updated(EcPoiEcTrack $pivot)
    {
        $this->updateEcTrackDataChain($pivot);
    }

    /**
     * Handle the EcPoiEcTrack "deleted" event.
     * Triggered when a POI is detached from a track.
     *
     * @return void
     */
    public function deleted(EcPoiEcTrack $pivot)
    {
        $this->updateEcTrackDataChain($pivot);
    }

    /**
     * Update the EcTrack data chain when POI relationships change.
     *
     * @return void
     */
    private function updateEcTrackDataChain(EcPoiEcTrack $pivot)
    {
        $ecTrackId = $pivot->ec_track_id ?? $pivot->getAttribute('ec_track_id');
        if ($ecTrackId) {
            $ecTrack = EcTrack::find($ecTrackId);
            if ($ecTrack) {
                $ecTrackService = app(EcTrackService::class);
                $ecTrackService->updateDataChain($ecTrack);
            }
        }
    }
}
