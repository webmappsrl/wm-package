<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Wm\WmPackage\Observers\EcPoiEcTrackObserver;

class EcPoiEcTrack extends Pivot
{
    public $incrementing = true;

    public function __construct(array $attributes = [])
    {
        $this->table = config('wm-package.ec_poi_track_pivot_table', 'ec_poi_ec_track');
        parent::__construct($attributes);
    }

    protected static function boot()
    {
        parent::boot();
        static::observe(EcPoiEcTrackObserver::class);
    }

    /**
     * Get the EcTrack (or configured model like HikingRoute) that owns this pivot relationship.
     * Uses config to resolve both the model class and the FK name.
     */
    public function ecTrack(): BelongsTo
    {
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $fkName = self::getTrackForeignKeyName();

        return $this->belongsTo($ecTrackModelClass, $fkName);
    }

    /**
     * Get the EcPoi that owns this pivot relationship.
     */
    public function ecPoi(): BelongsTo
    {
        return $this->belongsTo(EcPoi::class, 'ec_poi_id');
    }

    /**
     * Derive the track FK name from the configured ec_track_table.
     * e.g. 'ec_tracks' -> 'ec_track_id', 'hiking_routes' -> 'hiking_route_id'
     */
    public static function getTrackForeignKeyName(): string
    {
        $tableName = config('wm-package.ec_track_table', 'ec_tracks');

        return rtrim($tableName, 's') . '_id';
    }
}
