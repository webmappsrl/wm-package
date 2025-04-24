<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Builder;

trait OwnedByUserModel
{
    /**
     * Scope a query to only include current user EcPois.
     */
    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->user()->id);
    }

    /**
     * Alias for the user relation
     */
    public function author()
    {
        return $this->user();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
