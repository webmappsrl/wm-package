<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaxonomyWhereable extends MorphPivot
{
    protected $table = 'taxonomy_whereables';

    public $incrementing = true;

    protected static function boot()
    {
        parent::boot();
        TaxonomyWhereable::observe('Wm\\WmPackage\\Observers\\TaxonomyWhereablesObserver');
    }

    /**
     * Get the parent model that the where is associated with.
     */
    public function model(): MorphTo
    {
        return $this->morphTo('taxonomy_whereable');
    }

    /**
     * Get the taxonomy where that owns the relationship.
     */
    public function taxonomyWhere(): BelongsTo
    {
        return $this->belongsTo(TaxonomyWhere::class, 'taxonomy_where_id');
    }
}
