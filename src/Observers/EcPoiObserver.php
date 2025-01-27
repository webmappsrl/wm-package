<?php

namespace Wm\WmPackage\Observers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Wm\WmPackage\Models\EcMedia;
use Wm\WmPackage\Models\EcPoi;

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
}
