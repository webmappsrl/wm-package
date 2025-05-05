<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Models\Permission;

class PermissionPolicy
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
        if ($user->hasRole('Administrator') || $user->hasPermissionTo('manage roles and permissions')) {
            return true;
        }
    }

    public function viewAny(User $user): bool
    {

        return false;
    }

    public function view(User $user, Permission $model): bool
    {

        return false;
    }

    public function create(User $user): bool
    {

        return false;
    }

    public function update(User $user, Permission $model): bool
    {

        return false;
    }

    public function delete(User $user, Permission $model): bool
    {

        return false;
    }

    public function restore(User $user, Permission $model): bool
    {

        return false;
    }

    public function forceDelete(User $user, Permission $model): bool
    {

        return false;
    }
}
