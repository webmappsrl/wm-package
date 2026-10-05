<?php

namespace Wm\WmPackage\TrailRegistry\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Le istanze sono l'unico modello del Catasto che si crea e si modifica da
 * Nova (oc:8700).
 */
class TrailApplicationPolicy extends TrailRegistryPolicy
{
    public function create(Authenticatable $user): bool
    {
        return static::allows($user);
    }

    /**
     * Un'istanza si modifica solo in istruttoria (oc:8571): approvata o
     * respinta resta uno storico.
     */
    public function update(Authenticatable $user, Model $model): bool
    {
        return static::allows($user)
            && $model instanceof TrailApplication
            && $model->status === TrailApplicationStatus::UnderReview;
    }
}
