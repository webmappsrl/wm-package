<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\App;

class AppObserver extends AbstractObserver
{
    /**
     * Handle the App "saved" event.
     *
     * @return void
     */
    public function saved(App $app) {}

    /**
     * Handle the App "saving" event.
     *
     * @return void
     */
    public function saving(App $app)
    {
        $json = json_encode(json_decode($app->external_overlays));

        $app->external_overlays = $json;
    }

    /**
     * Handle the App "updated" event.
     *
     * @return void
     */
    public function updated(App $app) {}

    /**
     * Handle the App "deleted" event.
     *
     * @return void
     */
    public function deleted(App $app) {}
}
