<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stato del calcolo di corrispondenza di una UgcTrack (oc:8773).
 *
 * @property string $status
 * @property ?string $error
 * @property ?int $segment_length_meters
 * @property ?int $max_distance_meters
 * @property \Illuminate\Support\Carbon $computed_at
 */
class UgcTrackMatchingRun extends Model
{
    public const STATUS_COMPUTED = 'computed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'ugc_track_matching_runs';

    protected $primaryKey = 'ugc_track_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'computed_at' => 'datetime',
    ];
}
