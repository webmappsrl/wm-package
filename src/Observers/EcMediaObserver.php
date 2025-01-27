<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\User;
use Wm\WmPackage\Models\EcMedia;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Observers\AbstractObserver;

class EcMediaObserver extends AbstractObserver
{

    /**
     * Handle the EcMedia "saved" event.
     *
     * @return void
     */
    public function saved(EcMedia $ecMedia) {}


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

    /**
     * Handle the EcMedia "updated" event.
     *
     * @return void
     */
    public function updated(EcMedia $ecMedia) {}

    /**
     * Handle the EcMedia "deleted" event.
     *
     * @return void
     */
    public function deleted(EcMedia $ecMedia) {}
}
