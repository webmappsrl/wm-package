<?php

namespace Wm\WmPackage\Observers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\EcTrackService;

class EcPoiObserver extends AbstractEcObserver
{
    /**
     * Handle the EcMedia "deleted" event.
     *
     * @return void
     */
    public function deleting(EcPoi $ecPoi)
    {
        // TODO: impedisco di cancellare il poi oppure cancello le relazioni con le tracce?
        if ($ecPoi->ecTracks()->exists()) {
            throw new HttpException(500, 'Cannot delete this POI because it is linked to one or more tracks.');
        }
    }

    /**
     * Handle the EcMedia "saved" event.
     *
     * @return void
     */
    public function saved($ecPoi)
    {
        parent::saved($ecPoi);
        EcPoiService::make()->updateDataChain($ecPoi);

        // Aggiorna anche gli EcTrack collegati a questo EcPoi
        $ecTracks = $ecPoi->ecTracks;
        if ($ecTracks && $ecTracks->isNotEmpty()) {
            $ecTrackService = app(EcTrackService::class);
            foreach ($ecTracks as $ecTrack) {
                $ecTrackService->updateDataChain($ecTrack);
            }
        }

        // UserService::make()->assigUserAppIdIfNeeded(null, null, $ecPoi->app_id);
        $app = $ecPoi->app;
        if ($app) {
            BuildAppPoisGeojsonJob::dispatch($app->id);
        }
    }
}
