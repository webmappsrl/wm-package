<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Pbf\GenerateAppPBFJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Models\UserService;

class EcTrackObserver extends AbstractObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    public function __construct(protected GeometryComputationService $geometryComputationService, protected EcTrackService $ecTrackService) {}

    /**
     * Handle the EcTrack "saved" event.
     *
     * @return void
     */
    public function saved(EcTrack $ecTrack)
    {
        $this->ecTrackService->updateDataChain($ecTrack);

        UserService::make()->assigUserSkuAndAppIdIfNeeded($ecTrack->user, $ecTrack->sku, $ecTrack->app_id);
    }

    /**
     * Handle the EcTrack "saving" event.
     *
     * @return void
     */
    public function saving(EcTrack $ecTrack)
    {
        if (isset($ecTrack->properties['excerpt'])) {
            $properties = $ecTrack->properties;
            $properties['excerpt'] = substr($ecTrack->properties['excerpt'], 0, 255);
            $ecTrack->properties = $properties;
        }
    }

    /**
     * Handle the EcTrack "deleted" event.
     *
     * @return void
     */
    public function deleted(EcTrack $ecTrack)
    {

        /**
         * Delete track PBFs if the track has associated apps, a bounding box, and an author ID.
         * Otherwise, log an info message.
         *
         * @param  EcTrack  $ecTrack  The track to observe.
         * @return void
         */
        $apps = $ecTrack->trackHasApps();
        $author_id = $ecTrack->user->id;
        $bbox = $this->geometryComputationService->getGeometryModelBbox($ecTrack);
        if ($apps && $bbox && $author_id) {
            GenerateAppPBFJob::dispatch($apps, $bbox);
        } else {
            Log::info('No apps or bbox or author_id found for track ' . $ecTrack->id . ' to delete PBFs.');
        }
    }
}
