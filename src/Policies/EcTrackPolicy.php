<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\EcTrack;

class EcTrackPolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     *
     * @param  \App\Models\User  $user
     * @param  string  $ability
     * @return void|bool
     */
    public function before(User $user, $ability)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\User  $user
     * @param  \Wm\WmPackage\Models\EcTrack  $ecTrack
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, EcTrack $ecTrack)
    {
        // Admins are handled by before(). Users can view their own Tracks.
        return $user->id === $ecTrack->user_id;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\User  $user
     * @param  \Wm\WmPackage\Models\EcTrack  $ecTrack
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, EcTrack $ecTrack)
    {
        // Admins are handled by before(). Users can update their own Tracks.
        return $user->id === $ecTrack->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \Wm\WmPackage\Models\EcTrack  $ecTrack
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, EcTrack $ecTrack)
    {
        // Admins are handled by before(). Users can delete their own Tracks.
        return $user->id === $ecTrack->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\User  $user
     * @param  \Wm\WmPackage\Models\EcTrack  $ecTrack
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, EcTrack $ecTrack)
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \Wm\WmPackage\Models\EcTrack  $ecTrack
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, EcTrack $ecTrack)
    {
        return false;
    }
}
