<?php

namespace Wm\WmPackage\Listeners;

use Illuminate\Auth\Events\Login;

class UpdateLastLoginAt
{
    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(Login $event)
    {
        /**
         * @var \Wm\WmPackage\Models\User
         */
        $user = $event->user;
        $user->last_login_at = now();
        $event->user->save();
    }
}
