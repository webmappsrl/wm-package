<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Models\User;

class UserService extends MakeableService
{
    /**
     * Undocumented function
     *
     * @param  \Wm\WmPackage\Models\User  $user
     * @param  string|null  $sku
     * @param  string|null  $appId
     * @param  bool  $save  - If the model should be saved
     * @return \Wm\WmPackage\Models\User - the eventually updated User model
     */
    public function assigUserSkuAndAppIdIfNeeded($user, $sku = null, $appId = null, $save = true): User
    {
        if (is_null($user->sku) && ! is_null($sku)) {
            $user->sku = $sku;
        }

        if (is_null($user->appId) && ! is_null($appId)) {
            $user->app_id = $appId;
        }

        if ($save && $user->isDirty()) {
            $user->save();
        }

        return $user;
    }
}
