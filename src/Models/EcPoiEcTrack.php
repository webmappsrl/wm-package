<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Wm\WmPackage\Observers\EcPoiEcTrackObserver;

class EcPoiEcTrack extends Pivot
{
    protected $table = 'ec_poi_ec_track';

    protected static function boot()
    {
        parent::boot();
        static::observe(EcPoiEcTrackObserver::class);
    }

    /**
     * Get the EcTrack that owns this pivot relationship.
     */
    public function ecTrack()
    {
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }

    /**
     * Get the EcPoi that owns this pivot relationship.
     */
    public function ecPoi()
    {
        return $this->belongsTo(EcPoi::class, 'ec_poi_id');
    }
}
