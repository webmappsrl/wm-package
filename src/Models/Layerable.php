<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Wm\WmPackage\Observers\LayerableObserver;

class Layerable extends MorphPivot
{
    protected $table = 'layerables';

    public $incrementing = true;

    protected static function boot()
    {
        parent::boot();
        Layerable::observe(LayerableObserver::class);
    }

    /**
     * Get the parent model that the activity is associated with.
     */
    public function model(): MorphTo
    {
        return $this->morphTo('layerable');
    }

    /**
     * Get the layer that owns the relationship.
     */
    public function layer()
    {
        return $this->belongsTo(Layer::class, 'layer_id');
    }
}
