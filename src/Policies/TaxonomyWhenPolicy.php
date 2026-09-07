<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyWhen;

class TaxonomyWhenPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        if ($user->hasRole('Editor')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view(User $user, TaxonomyWhen $taxonomyWhen)
    {
        if ($user->hasRole('Editor')) {
            return true;
        }
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        //
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, TaxonomyWhen $taxonomyWhen)
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyWhen $taxonomyWhen)
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyWhen $taxonomyWhen)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyWhen $taxonomyWhen)
    {
        //
    }
}
