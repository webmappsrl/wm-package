<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
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
}
