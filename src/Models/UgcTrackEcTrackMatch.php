<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Segmenti di una EcTrack corrispondenti a una UgcTrack (oc:8773). */
class UgcTrackEcTrackMatch extends Model
{
    protected $table = 'ugc_track_ec_track_matches';

    protected $guarded = [];

    protected $casts = [
        'matched_segments' => 'array',
    ];

    public function ugcTrack(): BelongsTo
    {
        return $this->belongsTo(UgcTrack::class);
    }

    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(config('wm-package.ec_track_model', EcTrack::class), 'ec_track_id');
    }
}
