<?php

namespace Wm\WmPackage\Services\Models;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\BaseService;

class UserService extends BaseService
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
    public function assigUserAppIdIfNeeded($user, $sku = null, $appId = null, $save = true): User
    {
        if ($user->appId) {
            return $user;
        } elseif ($appId)
            $user->app_id = $appId;
        elseif ($sku) {
            $appId = App::where('sku', $sku)->first()->id ?? false;
            if ($appId)
                $user->app_id = $appId;
        }


        if ($save && $user->isDirty('app_id')) {
            $user->save();
        }

        return $user;
    }
}
