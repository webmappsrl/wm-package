<?php

namespace Wm\WmPackage\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\Tile;

class TilePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasRole('Administrator');
    }

    public function view(User $user, Tile $tile): bool
    {
        return $user->hasRole('Administrator');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Administrator');
    }

    public function update(User $user, Tile $tile): bool
    {
        return $user->hasRole('Administrator');
    }

    public function delete(User $user, Tile $tile): bool
    {
        return $user->hasRole('Administrator');
    }

    public function restore(User $user, Tile $tile): bool
    {
        return $user->hasRole('Administrator');
    }

    public function forceDelete(User $user, Tile $tile): bool
    {
        return $user->hasRole('Administrator');
    }
}

