<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\App;

class AppObserver extends AbstractObserver
{

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
}
