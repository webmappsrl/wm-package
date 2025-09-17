<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Wm\WmPackage\Observers\TaxonomyPoiTypeablesObserver;

class TaxonomyPoiTypeable extends MorphPivot
{
    protected $table = 'taxonomy_poi_typeables';

    public $incrementing = true;

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();
        self::observe(TaxonomyPoiTypeablesObserver::class);
    }

    /**
     * Get the parent model that the poi type is associated with.
     */
    public function model(): MorphTo
    {
        return $this->morphTo('taxonomy_poi_typeable');
    }

    /**
     * Get the taxonomy poi type that owns the relationship.
     */
    public function poiType()
    {
        return $this->belongsTo(TaxonomyPoiType::class, 'taxonomy_poi_type_id');
    }
}
