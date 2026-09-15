<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Policies\Concerns\AuthorizesViaBypassRoles;

class LayerPolicy
{
    use AuthorizesViaBypassRoles;
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, Layer $layer)
    {
        return $user->ownsApp($layer->app_id);
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, Layer $layer)
    {
        return $user->ownsApp($layer->app_id);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, Layer $layer)
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        // Admins are handled by before(). Any other non-Editor role (e.g. Validator)
        // can only delete Layers of their own app(s), mirroring view()/update().
        return $user->ownsApp($layer->app_id);
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, Layer $layer)
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, Layer $layer)
    {
        return true;
    }
}
