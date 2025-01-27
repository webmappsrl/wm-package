<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\User;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcMedia;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
