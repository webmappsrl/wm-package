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
        return true;
    }

    public function view(User $user, App $model): bool
    {
        return true;
    }

    public function update(User $user, App $model): bool
    {
        return true;
    }

    public function delete(User $user, App $model): bool
    {
        return true;
    }

    public function restore(User $user, App $model): bool
    {
        return true;
    }

    public function forceDelete(User $user, App $model): bool
    {
        return true;
    }

    public function emulate(User $user, App $model): bool
    {
        return true;
    }
}
