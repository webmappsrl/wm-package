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
     * Uses config('wm-package.ec_track_model') to resolve the correct model class,
     * and EcPoiEcTrack::getTrackForeignKeyName() to resolve the correct FK,
     *
     * @return void
     */
    private function updateEcTrackDataChain(EcPoiEcTrack $pivot)
    {
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $fkName = EcPoiEcTrack::getTrackForeignKeyName();

        $ecTrackId = $pivot->getAttribute($fkName);
        if ($ecTrackId) {
            $ecTrack = $ecTrackModelClass::find($ecTrackId);
            if ($ecTrack) {
                $ecTrackService = app(EcTrackService::class);
                $ecTrackService->updateDataChain($ecTrack);
            }
        }
    }
}
