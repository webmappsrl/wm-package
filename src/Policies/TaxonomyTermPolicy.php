<?php

namespace Wm\WmPackage\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * $user non ha tipo: negli shard e' App\Models\User, che nel package da solo non esiste.
 */
class TaxonomyTermPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny($user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return Response|bool
     */
    public function view($user, TaxonomyTerm $taxonomyTerm)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create($user)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update($user, TaxonomyTerm $taxonomyTerm)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete($user, TaxonomyTerm $taxonomyTerm)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore($user, TaxonomyTerm $taxonomyTerm)
    {
        return $user->hasRole('Administrator');
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete($user, TaxonomyTerm $taxonomyTerm)
    {
        return $user->hasRole('Administrator');
    }
}
