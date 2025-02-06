<?php

namespace Wm\WmPackage\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

class AppPolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Perform pre-authorization checks.
     *
     * @param  string  $ability
     * @return void|bool
     */
    public function before(User $user, $ability)
    {
        if ($user->hasRole('Admin')) {
            return true;
        }
        if ($user->isInDefaultRoles($user)) {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->can('view_apps') ||
            $user->can('view_self_apps');
    }

    public function view(User $user, App $model): bool
    {
        return $user->can('view_apps') ||
            ($user->id === $model->user_id && $user->can('view_self_apps'));
    }

    public function update(User $user, App $model): bool
    {
        if ($user->hasRole('Editor') && $user->id === $model->user_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, App $model): bool
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return $user->can('delete_self_apps');
    }

    public function restore(User $user, App $model): bool
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return $user->can('delete_apps');
    }

    public function forceDelete(User $user, App $model): bool
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return $user->can('delete_apps');
    }

    public function emulate(User $user, App $model): bool
    {
        if ($user->hasRole('Editor')) {
            return false;
        }

        return $user->hasRole('Admin') && $user->id !== $model->id;
    }
}
