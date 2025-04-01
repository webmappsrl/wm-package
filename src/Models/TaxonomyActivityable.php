<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Wm\WmPackage\Observers\TaxonomyActivityablesObserver;

class TaxonomyActivityable extends MorphPivot
{
    protected $table = 'taxonomy_activityables';

    public $incrementing = true;

    protected static function boot()
    {
        parent::boot();
        TaxonomyActivityable::observe(TaxonomyActivityablesObserver::class);
    }

    /**
     * Get the parent model that the activity is associated with.
     */
    public function model(): MorphTo
    {
        return $this->morphTo('taxonomy_activityable');
    }
    /**
     * Get the taxonomy activity that owns the relationship.
     */
    public function activity()
    {
        return $this->belongsTo(TaxonomyActivity::class, 'taxonomy_activity_id');
    }
}
