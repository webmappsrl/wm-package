<?php

namespace Wm\WmPackage\Observers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Models\EcPoiService;
use Wm\WmPackage\Services\Models\UserService;

class EcPoiObserver extends AbstractObserver
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
    public function saved(EcPoi $ecPoi)
    {
        if (! $ecPoi->skip_geomixer_tech && ! empty($ecPoi->geometry)) {
            EcPoiService::make()->updateDataChain($ecPoi);
        }

        UserService::make()->assigUserSkuAndAppIdIfNeeded($ecPoi->user, $ecPoi->sku, $ecPoi->app_id);
    }
}
