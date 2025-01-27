<?php

namespace App\Observers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\GeometryComputationService;

class AppObserver
{

    /**
     * Handle the App "saved" event.
     *
     * @return void
     */
    public function saved(App $app) {}

    /**
     * Handle the App "creating" event.
     *
     * @return void
     */
    public function creating(App $app)
    {
        $user = User::getEmulatedUser();
        if (is_null($user)) {
            $user = User::where('email', '=', 'team@webmapp.it')->first();
        }
        $app->author()->associate($user);
    }

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
