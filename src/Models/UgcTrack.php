<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Traits\OwnedByUserModel;
use Wm\WmPackage\Traits\UgcAbleModel;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Models\Abstracts\Linestring;

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
class UgcTrack extends Linestring
{
    use OwnedByUserModel;

    protected static function booted()
    {
        UgcTrack::observe(UgcObserver::class);
    }
}
