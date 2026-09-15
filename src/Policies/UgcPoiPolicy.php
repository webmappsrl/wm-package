<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Policies\Concerns\AuthorizesViaBypassRoles;

class UgcPoiPolicy
{
    use AuthorizesViaBypassRoles;
    use HandlesAuthorization;

    /**
     * Administrator and Validator see/manage any UGC regardless of app (same
     * treatment as the menu-visibility decision: a Validator's job is to
     * validate UGC across apps, not just their own).
     *
     * @return array<int, string>
     */
    protected function bypassRoles(): array
    {
        return ['Administrator', 'Validator'];
    }

    /**
     * Determine whether the user can view any models.
     *
     * `hasUgcEnabled()` (not `hasDashboardShow()`) so this matches the same
     * criterion the Nova menu's canSee() uses for the UGC section — otherwise
     * an Editor could see the menu entry and still be denied access to it.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        if ($user->hasRole('Editor') && $user->hasUgcEnabled()) {
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
        if ($user->hasRole('Editor') && $user->hasUgcEnabled()) {
            return $user->ownsApp($ugcPoi->app_id);
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
