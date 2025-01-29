<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\User;

abstract class AbstractObserver
{
    /**
     * Handle the Model "creating" event.
     *
     * @return void
     */
    public function creating(Model $model)
    {
        $user = auth()->user();
        if (is_null($user)) {
            $user = User::where('email', '=', 'team@webmapp.it')->first();
        }
        $model->author()->associate($user);
    }
}
