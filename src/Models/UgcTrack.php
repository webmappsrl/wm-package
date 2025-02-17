<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Traits\OwnedByUserModel;

/**
 * Class UgcTrack
 *
 *
 * @property int    id
 * @property string sku
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 */
class UgcTrack extends MultiLineString
{
    use OwnedByUserModel;

    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    protected static function booted()
    {
        UgcTrack::observe(UgcObserver::class);
    }
}
