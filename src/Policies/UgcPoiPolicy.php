<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiPolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     *
     * Administrator and Validator see/manage any UGC regardless of app (same
     * treatment as the menu-visibility decision: a Validator's job is to
     * validate UGC across apps, not just their own).
     *
     * @return void|bool
     */
    public function before(User $user, string $ability)
    {
        if ($user->hasRole('Administrator') || $user->hasRole('Validator')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        if ($user->hasRole('Editor') && $user->hasDashboardShow()) {
            return true;
        }
    }

    /**
     * Determine whether the user can view the model.
     *
     * Editor: read-only, limited to UGC of their own app(s).
     *
     * @return Response|bool
     */
    public function view(User $user, UgcPoi $ugcPoi)
    {
        if ($user->hasRole('Editor') && $user->hasDashboardShow()) {
            return $user->ownedAppIds()->contains($ugcPoi->app_id);
        }
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, UgcPoi $ugcPoi)
    {
        return false;
    }
}
