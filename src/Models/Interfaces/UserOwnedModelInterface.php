<?php

namespace Wm\WmPackage\Models\Interfaces;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface UserOwnedModelInterface
{
    /**
     * Scope a query to only include current user models.
     */
    public function scopeCurrentUser(Builder $query): Builder;

    /**
     * Get the user that owns the model.
     */
    public function user(): BelongsTo;

    /**
     * Alias for the user relation.
     */
    public function author(): BelongsTo;
}