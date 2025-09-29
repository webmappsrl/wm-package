<?php

namespace Wm\WmPackage\Observers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\UserService;

class EcPoiObserver extends AbstractEcObserver
{
    /**
     * Handle the EcMedia "deleted" event.
     *
     * @return void
     */
    public function deleting(EcPoi $ecPoi)
    {
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
        if (! empty($ecPoi->geometry)) {
            EcPoiService::make()->updateDataChain($ecPoi);
        }

        // UserService::make()->assigUserAppIdIfNeeded(null, null, $ecPoi->app_id);
        $app = $ecPoi->app;
        if ($app) {
            BuildAppPoisGeojsonJob::dispatch($app->id);
        }
    }
}
