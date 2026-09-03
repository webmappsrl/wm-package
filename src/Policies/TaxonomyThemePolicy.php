<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyTheme;

class TaxonomyThemePolicy
{
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
    public function view(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyTheme $taxonomyTheme)
    {
        return $user->hasRole('Administrator');
    }
}
