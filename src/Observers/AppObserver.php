<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppConfigService;

class AppObserver extends AbstractObserver
{
    /**
     * Handle the App "saving" event.
     *
     * @return void
     */
    public function saving($app)
    {
        parent::saving($app);
        $json = json_encode(json_decode($app->external_overlays));

        $app->external_overlays = $json;
    }

    // public function save( App $app )
    // {
    //     new AppConfigService($app)->writeAppConfigOnAws();
    // }
}
