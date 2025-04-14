<?php

namespace Wm\WmPackage\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;

class EcTrackPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EcTrack $model): bool
    {
        if ($user->hasRole('Editor') && $user->id === $model->user_id) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($user->hasRole('Editor')) {
            return true;
        }

        return false;
    }

    public function update(User $user, EcTrack $model): bool
    {
        if ($user->hasRole('Editor') && $user->id === $model->user_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, EcTrack $model): bool
    {
        if ($user->hasRole('Editor')) {
            return true;
        }

        return false;
    }

    public function restore(User $user, EcTrack $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, EcTrack $model): bool
    {
        return false;
    }
}
