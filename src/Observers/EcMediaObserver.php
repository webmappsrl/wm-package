<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcMedia;

class EcMediaObserver extends AbstractObserver
{
    /**
     * Handle the EcMedia "saving" event.
     *
     * @return void
     */
    public function created(EcMedia $ecMedia)
    {
        try {
            $ecMedia->updateDataChain($ecMedia);
        } catch (\Exception $e) {
            Log::error($ecMedia->id . 'created  EcMedia: An error occurred during a store operation: ' . $e->getMessage());
        }
    }
}
