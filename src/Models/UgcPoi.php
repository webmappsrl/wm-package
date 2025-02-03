<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Point;
use Wm\WmPackage\Traits\UgcAbleModel;

/**
 * Class UgcPoi
 *
 *
 * @property int    id
 * @property string app_id
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 * @property mixed  ugc_media
 */
class UgcPoi extends Point
{
    use UgcAbleModel;

    /**
     * @var mixed|string
     */
    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'description',
        'geometry',
        'properties',
    ];
}
